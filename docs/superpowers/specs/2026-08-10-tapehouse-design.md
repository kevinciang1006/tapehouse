# Tapehouse — Design Spec

**Date:** 2026-08-10
**Status:** approved, pending implementation plan
**Source documents:** `docs/Tapehouse_PRD.md`, `docs/Tapehouse_Design_Prompt.md`, `docs/Tapehouse_Claude_Code_Prompt.md`, `docs/design/Tapehouse.dc.html`

---

## 1. What is being built

Tapehouse is an internal operations console for a live market data feed. It ingests price quotes
from the Twelve Data API, caches them in Redis, persists them to PostgreSQL, rebroadcasts them to
browsers over WebSocket, and shows an operator whether the feed is healthy and how much of the API
budget has been burned.

It is a demo project for a Twelve Data job application. The stack is chosen to match that job
description, not personal preference. That constraint is the point of the project and is stated in
the README rather than hidden.

## 2. Decisions made during brainstorming

These override the source documents wherever they conflict.

| # | Decision | Supersedes |
|---|---|---|
| D1 | PHP **8.4** everywhere — brew, Docker base, CI. Pest **5**. | Build prompt's "PHP 8.3" / `php:8.3-fpm-alpine` |
| D2 | A third **`SimulatedDriver`** behind `UpstreamDriver`, config-gated | Two drivers only |
| D3 | **Per-driver stale thresholds** in config | Fixed 30s in design prompt |
| D4 | `alert_rules` gains a **`metric`** enum (`price`, `change_pct`) | Schema with condition only |
| D5 | **Feed stop/start** via a Redis control flag plus two endpoints | No such endpoint in either doc |
| D6 | Laravel app at **repo root**; source docs under `docs/` | `composer create-project laravel/laravel tapehouse` (nested) |
| D7 | Scope: P0 + alerts + Docker/CI/README. **Sparkline drawer excluded.** | P1 ambiguity |
| D8 | `git init`, **Git Flow** — `main`, `develop`, `feature/*` | Non-repository directory |

### Rationale for the non-obvious ones

**D1.** Pest 5.0.4 requires PHP `^8.4`; the last 8.3-compatible release is Pest 4.7.8. The build
prompt says "PHP 8.3 **minimum**", so 8.4 complies with it as written. Laravel 13.24, Reverb 1.11,
predis 3.5, ratchet/pawl 0.4 and Larastan 3.10 all support 8.4.

**D2 and D3 — the credit arithmetic.** Twelve Data's `/quote` endpoint charges **one credit per
symbol**, not per request; batching saves round-trips, not budget. The trial allows 8 credits per
minute. Against a 10-symbol watchlist that is one refresh per symbol every ~75 seconds, which means:

- every row exceeds the design's 30s stale threshold permanently, so the entire tape renders in the
  stale treatment — muted ages, 2px muted left borders;
- the flash fires roughly 0.13 times per second, against the ~5/sec the design's "several rows
  mid-decay at any moment" requires. A ~40× shortfall.

The flash is described in the design prompt as "the whole personality of the interface". On a trial
key over polling it cannot exist. Two corrections follow. Staleness becomes driver-relative, because
"stale" should mean *this feed is behind*, not *this account is on a free plan*. And a
`SimulatedDriver` supplies dense ticks for development and for the deployed demo.

The simulated driver is **not disguised**. It reports `simulated` in the ops panel driver field and
in `feed_events`, exactly as `websocket` and `polling` do. Real drivers remain primary and take over
whenever the key permits. This matters because the PRD has an explicit honesty section; a demo that
silently faked its data would contradict it.

**D4.** The designed alerts panel lists rules across three metrics (`price > 230.00`,
`change% < -2.00`, `stale > 60s`) while the specified `alert_rules` table carries only
`condition(above|below)` and `threshold` — no metric column. The panel as designed cannot be built
on the table as specified. Adding `metric` with `price` and `change_pct` covers four of the six
mocked rules and keeps evaluation purely tick-driven, since both values arrive on every tick.

`stale_seconds` is excluded deliberately: staleness fires on the **absence** of a tick, so it cannot
be evaluated from a tick batch. It would need a separate scheduled sweep — a second evaluation path
and a second failure mode for the sake of one mocked row. The `stale > 60s` row in the mock is
replaced with a `change_pct` rule.

**D5.** The status bar has a `Stop feed` button with no endpoint behind it in any document. The web
process and the ingest loop are separate processes (separate containers in production), so the
control cannot be a signal or in-process state. A Redis key that the loop reads each pass is the
mechanism that works across both.

## 3. Architecture

### 3.1 Ingest — one loop, three drivers

`TapeIngest` owns a single ReactPHP event loop. `DriverManager` holds the current driver. A 250ms
timer calls `current()->tick()` then `supervise()`.

```
TapeIngest (long-running artisan command)
└── ReactPHP loop
    ├── 250ms timer  → DriverManager::current()->tick()
    │                 → DriverManager::supervise()      demotion / promotion / control flag
    ├── 1s timer     → TickBuffer::flushIfDue()
    └── signal trap  → SIGTERM/SIGINT: flush, stop driver, feed_event, exit

DriverManager
├── WebSocketDriver   primary    push, pawl on the shared loop
├── PollingDriver     fallback   pull, credit-budgeted
└── SimulatedDriver   dev/demo   random walk, config-gated
```

**`WebSocketDriver`** connects to `wss://ws.twelvedata.com/v1/quotes/price` via `ratchet/pawl` on
the shared loop and pushes quotes through the `onQuote` callback. Its `tick()` performs **no I/O** —
it only evaluates liveness: age of last message, consecutive failure count. Auth rejection is an
expected outcome on a trial key, handled and demoted, never thrown.

**`PollingDriver`** is pull-based. Each `tick()`:

1. Takes the next slice from `tape:poll:cursor`, of size `poll.batch_size` (default 8).
2. Requests `count($slice)` tokens from `CreditBudget` — one per symbol, matching the real charge.
3. **Honours partial grants.** Five tokens against an eight-symbol slice polls five symbols and
   advances the cursor past exactly those five. Under sustained starvation the cursor rotates
   through the whole watchlist instead of starving its tail.
4. Issues one async HTTP request for the granted symbols; the response fans out to `onQuote`.

**`SimulatedDriver`** generates a random walk per symbol at a configured interval, produces `Quote`
DTOs identical in shape to the real drivers, and consumes no credits.

**Demotion and promotion.** Demote to `PollingDriver` after `driver.failure_threshold` (3)
consecutive WebSocket failures, or immediately on auth rejection. Promote back on the backoff
schedule 60s, 120s, 300s, then every 300s. Every transition writes a `feed_events` row, updates
`tape:driver:state`, and broadcasts `FeedStateChanged`.

### 3.2 The quote path

Each `Quote` fans out to four consumers, none of which may block the loop:

| Consumer | Store | Purpose |
|---|---|---|
| `QuoteCache` | Redis hash `tape:quote:{ticker}`, TTL 1h | **The only read path for current price.** `GET /api/quotes` reads this and never touches Postgres. |
| `TickBuffer` | Postgres `ticks` | Audit path. Accumulates in memory; single multi-row insert at 200 rows or 1s, whichever first. Never one insert per tick. Flushes on shutdown. |
| `FeedMetrics` | Redis | `recordLag` pushes to `tape:metrics:lag`, LTRIM 500; per-minute tick counters, TTL 300s. |
| `QuoteBroadcaster` | Reverb | Coalesces into a 250ms window per channel, then one `QuotesUpdated` carrying an array. One fast symbol must not produce 50 broadcasts per second. |

`EvaluateAlerts` is dispatched **once per broadcast batch**, onto the Redis queue, never inline and
never per tick. A slow alert rule must not be able to stall ingest.

### 3.3 Credit budget

`CreditBudget` is a Redis token bucket with lazy refill. The whole read-modify-write — elapsed time,
tokens to add, cap at capacity, write both keys, attempt consumption — executes in a **single Lua
script via `EVAL`** so it is atomic across concurrent workers. Default capacity 8, refill 8 per 60s,
both from config.

```php
public function tryConsume(int $tokens = 1): int   // returns tokens actually granted (0..$tokens)
public function available(): int
public function capacity(): int
```

`tryConsume` returns the granted count rather than a boolean, because partial grants are what make
the rotating cursor work.

### 3.4 Feed control

```
POST /api/ops/feed/stop   → SET tape:control:state stopped
POST /api/ops/feed/start  → SET tape:control:state running
```

`DriverManager::supervise()` reads the key each pass. On a transition to `stopped` it stops the
current driver, writes a `warn` `feed_events` row, and broadcasts `FeedStateChanged`. The status bar
button flips between `Stop feed` and `Start feed` from the broadcast state.

## 4. Data model

As specified in the PRD, with these changes:

- **`alert_rules`** gains `metric` (string, enum-cast `price|change_pct`) before `condition`.
- **`ticks`** has no `updated_at`; ticks are immutable and the model disables timestamps.
- All money is `numeric`/`decimal(18,8)` — never float, never string.

Redis keys are as the PRD lists, plus `tape:control:state`.

Enums are backed PHP enums, no string literals: `AssetType`, `DriverState` (`websocket`, `polling`,
`simulated`, `stopped`), `TickSource`, `AlertMetric`, `AlertCondition`, `FeedEventLevel`.

## 5. REST API

All under `/api`, `auth:web`, `throttle:120,1`, returning API Resources rather than models. As the
build prompt lists, plus:

```
POST   /api/ops/feed/stop
POST   /api/ops/feed/start
```

`POST /api/alert-rules` and `PATCH /api/alert-rules/{id}` accept `metric`. Form Requests for every
write. Policies, not inline checks, for ownership of watchlists and alert rules.

## 6. Frontend

Server-rendered Blade shell; all data over the JSON API and Echo. No SPA router. jQuery 3.7 plus
vanilla ES modules. SCSS compiled by a hand-written Webpack 5 config.

The `.dc.html` export carries exact geometry and colour, so the SCSS is transcription rather than
interpretation. Values that must be reproduced precisely:

- Status bar 48px; degraded banner 34px; left rail 56px; right panel 320px; panel headers 44px;
  table rows 52px.
- **Price columns are split into two fixed-width spans** — integer part 92px right-aligned in `ink`,
  fraction part 64px left-aligned in `muted`. This, not `tabular-nums` alone, is what holds the
  decimal aligned across 2-decimal equities, 5-decimal forex, and thousands-separated crypto.
  Numeric grid is `1fr 124px 84px 54px` (last, change, change %, age).
- Flash: background wash at 12% opacity, decaying to transparent over 600ms on
  `cubic-bezier(0.16, 1, 0.3, 1)`. Nothing moves — no scale, slide, or pulse.
- Stale row: age value shifts to `muted`, left border thickens to 2px `muted`.
- Hover: 1px `signal` left border, nothing else.
- `prefers-reduced-motion`: decay replaced by a 1px `up`/`down` left border persisting 600ms.

Colour discipline is absolute: `up`/`down` only on price deltas and flashes, `signal` only on
interactive elements. Nothing else in the interface is coloured.

Module responsibilities:

| Module | Responsibility |
|---|---|
| `api.js` | fetch wrapper, CSRF from meta tag, typed errors on non-2xx |
| `echo.js` | Echo + Reverb instance; exposes `onReconnect(cb)` |
| `format.js` | precision by asset type, signed change, relative age |
| `flash.js` | directional flash; **WeakMap of pending timers keyed by element** so rapid ticks reset rather than stack; reduced-motion variant |
| `tape.js` | renders the table, **patches only changed cells**, 1s age interval, stale treatment; on Echo reconnect refetches `GET /api/quotes` and repaints before resuming |
| `ops.js` | polls `/api/ops/health` every 3s, subscribes to `private-ops`, renders driver/credits/lag/reconnects/queue depth, tails the event log |
| `watchlist.js` | symbol search, 250ms debounce, add/remove |
| `alerts.js` | rule CRUD including the metric selector, fired-events list |

The left rail switches the **right panel** between `ops` and `alerts`; `tape` is the main area and
is always present.

**Snapshot-then-stream on reconnect** is required. A client that reconnects has a gap; refetching
the REST snapshot before resuming closes it. Without it the tape silently shows stale prices after
any network blip.

## 7. Testing

Pest 5 on PHP 8.4. **Real Redis and real Postgres** — the token bucket's Lua script cannot be
mocked, so CI runs service containers.

Unit, in order of importance:

- `CreditBudget` — consumes to zero, refuses at zero, **grants partially**, refills at the correct
  rate over simulated time, atomic under concurrent consumption
- `DriverManager` — demotes after exactly the threshold, demotes immediately on auth rejection,
  promotes on the backoff schedule, writes one `feed_event` per transition, honours the control flag
- `PollingDriver` — advances the cursor by the **granted** count under starvation and covers every
  symbol across successive starved passes
- `TickBuffer` — flushes at 200 rows, at the time threshold, and on shutdown; inserts as one query
- `EvaluateAlerts` — fires on condition met, suppresses inside cooldown, fires again after it,
  handles `above`/`below` across both metrics

Feature: every endpoint's happy path, validation failure, and unauthenticated 401; cross-user 403 on
watchlists and alert rules; `GET /api/quotes` reads Redis and issues no Postgres query.

## 8. Code quality gates

`declare(strict_types=1)` in every PHP file. Constructor property promotion, readonly where
immutable. Full parameter and return types including closures. Backed enums for every status field.
No business logic in controllers — validate, delegate, return a Resource. **No facades inside
`app/Services/**`** — constructor injection only, so services unit-test without the container. All
config through `config/tapehouse.php`; never `env()` outside config files. Eager-load to avoid N+1.

Pint (Laravel preset) and Larastan level 6 must pass before any commit.

## 9. Build sequence

Ordered so that nothing is built on an unverified foundation.

1. Toolchain: brew `php@8.4` + composer, start Redis
2. Laravel 13 skeleton at repo root, Vite removed entirely, Docker Compose, Postgres + Redis connectivity
   — `composer create-project` refuses a non-empty target, so scaffold into a temporary directory
   and move the contents in over `docs/` and `.git/`, merging rather than replacing `.gitignore`
3. Migrations, models, enums, seeders
4. `CreditBudget` + tests — the Lua script is correct before anything depends on it
5. `TwelveDataClient`, `Quote` DTO, `PollingDriver` + tests
6. `WebSocketDriver`, `SimulatedDriver`, `DriverManager` + tests
7. `QuoteCache`, `TickBuffer`, `FeedMetrics`, `QuoteBroadcaster` + tests
8. `TapeIngest` — **verified end-to-end against the live key before any frontend work**
9. Reverb, events, channels
10. REST API, Resources, policies, feature tests
11. Webpack config, SCSS tokens, Blade shell, login
12. `tape.js`, `flash.js`, `format.js` — the live tape
13. `ops.js` — the ops panel and feed control
14. Alerts: job, endpoints, `alerts.js`
15. Production Dockerfile, supervisord, nginx WebSocket proxy
16. CI, `CLAUDE.md`, `README.md`

Step 8 is the gate. If the ingest loop does not produce real quotes, nothing downstream matters.

## 10. Out of scope

Symbol detail drawer and canvas sparkline. Multi-user tenancy, registration, password reset,
historical backfill, candlestick charts, indicators, portfolio/PnL, mobile layout, i18n, dark mode,
admin roles. Deployment to Cloud Run is a separate exercise from this build.

## 11. Honesty constraints carried into the README

Stated plainly, not hidden: Redis was learned on this project and is not production experience; the
last production Laravel was February 2022; Webpack was used because the JD names it, over a daily
Vite habit; jQuery was chosen deliberately for the same reason. The deployed demo runs on a Twelve
Data trial key and degrades to polling when streaming credits are exhausted, which is the intended
behaviour. Where the simulated driver is active, the ops panel says so.
