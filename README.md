# Tapehouse

## 1. What this is

Tapehouse is an internal market-data operations console: it ingests live
price quotes from [Twelve Data](https://twelvedata.com), caches and audits
them, rebroadcasts them to browser clients over WebSocket, and shows an
operator whether the upstream feed is healthy — current driver, credit
budget consumed, ingest lag, reconnects — alongside a threshold-alerting
layer on top of the same tick stream. It was built as a demo for a specific
Laravel/jQuery/PostgreSQL/Redis job description; see §2 below for why that
matters to how it's built.

Live demo: **tapehouse.kevinciang.com** *(placeholder — not yet deployed)*.

## 2. Why this stack

Laravel, jQuery, Webpack, PostgreSQL and Redis were chosen because they are
the stack named in the Twelve Data job description this project targets —
not because they are this author's daily tools. The author's daily stack is
React and Vite. Building an internal tool the way Twelve Data's own team
would build one, in their stack rather than a preferred one, is the point of
the exercise. See §5 for the honest version of what that trade cost.

## 3. Architecture

**Dual-driver ingest with automatic demotion.** `tape:ingest` runs a
`DriverManager` in front of two implementations of the same `UpstreamDriver`
interface: a `WebSocketDriver` (primary, streams from Twelve Data's
WebSocket feed) and a credit-budgeted `PollingDriver` (fallback, polls
`GET /quote`). The manager starts on the primary; after a configured run of
consecutive failures or an auth rejection it demotes to the fallback,
retries promotion on a backoff schedule (`60s, 120s, 300s`, capped), and
writes every transition to `feed_events` and the ops panel. A third
`SimulatedDriver` generates a random walk for local development or a
credit-exhausted demo and always reports itself as `simulated` — never as
`websocket` or `polling` — because the data underneath it isn't live.

**Redis-read / Postgres-audit split.** The tape's hot read path — "what's
the last price for AAPL" — comes from a Redis hash (`tape:quote:{ticker}`),
never from Postgres. Every tick is *also* appended to the `ticks` table in
Postgres through a buffered batch writer (flush on 200 rows or 1 second,
whichever comes first), which exists purely as the audit trail and the
source for history/sparkline reads. Postgres is never in the hot path for
"what is the price right now."

**Broadcast coalescing.** A fast-moving symbol can tick many times a
second; broadcasting one Reverb event per tick would give the browser more
repaints than a human eye resolves and risks saturating the socket.
`QuoteBroadcaster` buffers incoming quotes per user and flushes one
`QuotesUpdated` event per 250ms window, keeping only the latest price per
ticker in that window.

**Credit budgeting.** The `PollingDriver` must acquire a token from a Redis
token-bucket (`CreditBudget`) before every upstream request; the bucket
refills at a configured rate and, when starved, polls symbols on a rotating
priority cursor rather than dropping any of them. See PRD §7.5 and §7.7.

**Alerting off the ingest path.** Every tick batch dispatches
`EvaluateAlerts` onto the Redis queue rather than evaluating alert rules
inline — a slow or buggy rule cannot stall ingest. A firing rule writes an
`alert_events` row and broadcasts `AlertFired`, respecting a per-rule
cooldown so a symbol oscillating around a threshold doesn't spam.

```
                    ┌─────────────────┐
                    │  tape:ingest     │
                    │  (DriverManager) │
                    └───┬─────────┬────┘
           WebSocketDriver   PollingDriver   SimulatedDriver
           (primary)         (fallback,      (dev / exhausted
                              credit-budgeted) credits)
                    │
                    ▼
     ┌──────────────────────────────┐
     │ per tick:                    │
     │  → Redis tape:quote:{ticker} │  hot read path (tape, /api/quotes)
     │  → TickBuffer → Postgres     │  audit path (history, sparkline)
     │  → EvaluateAlerts (queued)   │  never inline
     └───────────────┬───────────────┘
                      │ coalesced 250ms/user
                      ▼
             QuoteBroadcaster → Reverb → browser (Echo/pusher-js)
```

## 4. Performance decisions

From `docs/Tapehouse_PRD.md` §7 — one line each:

1. **Buffered batch insert, not insert-per-tick** for `ticks` — flush on 200 rows or 1 second, designed for the case where it's 8,000 symbols, not 8.
2. **Redis as the read path, Postgres as the audit path** — the tape never waits on Postgres for the current price.
3. **Alert evaluation is queued, never inline** — a slow rule cannot stall ingest; queue depth is visible on the ops panel.
4. **Broadcast batching** — ticks coalesce into one broadcast per 250ms window per channel instead of one event per tick.
5. **Token bucket over naive rate limiting** — allows burst up to capacity and self-throttles instead of wasting or overrunning a fixed sleep budget.
6. **Snapshot-then-stream on reconnect** — a reconnecting client refetches a REST snapshot before resuming the stream, closing the gap a network blip would otherwise leave stale.
7. **Rotating priority cursor when budget-starved** — under polling with 8 credits/min and 20 watchlist symbols, viewport symbols rotate first rather than starving arbitrarily.

## 5. What this project is and isn't

Per the PRD's honesty constraints — naming a gap here is worth more than an
interviewer finding it first:

- **Redis was learned on this project.** It is not production experience. The token bucket and metrics counters are original to this build and explainable line by line, but that is not the same claim as having run Redis in production.
- **The last production Laravel was February 2022.** This project is current-Laravel (13); it represents refreshed knowledge, not unbroken continuity.
- **Webpack, not Vite.** The author's daily build tool is Vite. Webpack is used here — configured from scratch, not copied from a starter — because the job description names it.
- **jQuery, deliberately, for the same reason.** It is genuinely load-bearing, not vestigial: `resources/js/modules/watchlist.js` and `resources/js/modules/alerts.js` both use it for DOM wiring and event delegation. `resources/js/modules/tape.js` — the per-frame hot path that repaints prices on every tick — is deliberately vanilla JS with zero jQuery, because that loop runs often enough that the abstraction isn't worth it there.
- **The simulated driver never dresses up as live.** Where `SimulatedDriver` is active, the ops panel reports the driver as `simulated` (never `websocket` or `polling`), and lag reads `0ms` — because generated ticks are stamped with the same instant for both "quoted" and "received," and there genuinely is no network transit to measure.

## 6. Running locally

Requires Docker and Docker Compose.

```bash
docker compose up -d --build
docker compose exec app php artisan migrate:fresh --seed
```

This brings up seven services — `postgres`, `redis`, `app` (php-fpm),
`nginx`, `reverb`, `queue`, and `ingest` — and seeds 40 symbols plus one
operator account. Visit **http://127.0.0.1:8000** and sign in with:

```
email:    operator@tapehouse.dev
password: tapehouse
```

Without a Twelve Data API key, run with the bundled simulator instead of
hitting the real upstream:

```bash
TAPEHOUSE_SIMULATOR_ENABLED=true docker compose up -d --build
```

## 7. Known limitations

- **`tape:ingest` reads the watchlist only at boot.** Adding a symbol to the console does not make it tick — the ingest process built its ticker list once at startup and does not re-read it. It needs a restart (`docker compose restart ingest`) to pick up a newly-added symbol. This is the limitation most likely to bite a reviewer, because the console's own "add to watchlist" action invites exactly the workflow that doesn't work without that restart.
- **A queue worker must be running, or nothing reaches the browser.** `QuotesUpdated` and `AlertFired` are queued `ShouldBroadcast` events on the Redis queue; ingest only ever enqueues them. Without `php artisan queue:work` running, ingest keeps logging ticks and Reverb keeps reporting healthy, but every open tape freezes at its last price and never errors — the diagnostic is `queue_depth` climbing without bound on the ops panel.
- **What has and has not been verified against the live upstream.** Both transports have run against the real Twelve Data API: REST polling wrote real quotes (SPY 770.56, NVDA 217.50, MSFT 503.81, AAPL 304.91), and the WebSocket driver streamed real ticks (XAU/USD 4395.22, BTC/USD 63,821.15, EUR/USD 1.15388) — reconfirmed on a later run with fresh prices during this same development effort. The automatic **demotion** path, however, has never fired against a genuine upstream rejection — it is unit-tested only (`tests/Feature/Upstream/DriverManagerTest.php`), because the trial key in use has never actually been rejected. Do not read the WebSocket/polling verification as also covering demotion; it doesn't.
- **Under polling, "lag" measures quote age, not network transit.** Twelve Data's `/quote` endpoint returns the quote's own last-trade timestamp, and `PollingDriver` uses that as `quotedAt`. Against a closed market, that timestamp can be hours old, so the ops panel's p50/p95 reads as hours — which is honest (the data really is that old) but is not the sub-second transit figure the streaming path produces when the market is open.

## 8. Testing

```bash
npm run build          # must run first — see CLAUDE.md
npm run test:js        # JS unit tests (node --test)
vendor/bin/pint --test
vendor/bin/phpstan analyse --memory-limit=512M
php artisan test       # or vendor/bin/pest
```

The suite (240 Pest tests / 26 JS tests as of this writing) genuinely
covers:

- **The token bucket's atomicity and partial grants** — `tests/Feature/Budget/CreditBudgetTest.php` (`is atomic across concurrent consumers`, `grants partially rather than refusing outright`).
- **Driver demotion and promotion** — `tests/Feature/Upstream/DriverManagerTest.php` (demotion on unhealthy primary, backoff-gated promotion, escalating backoff, control-flag stop/resume).
- **The polling cursor under starvation** — `tests/Feature/Upstream/PollingDriverTest.php` (`advances the cursor by the GRANTED count, not the requested slice`, `covers every symbol across successive starved passes`, `makes no request at all when the budget is empty`).
- **Tick-buffer batching** — `tests/Feature/Quotes/TickBufferTest.php` (fills, time-threshold flush, single-batch insert, sub-second precision preserved).
- **Alert cooldowns** — `tests/Feature/Jobs/EvaluateAlertsTest.php` (`suppresses a second fire inside the cooldown`, `fires again once the cooldown has passed`).
- **Cross-operator isolation** — `tests/Feature/Api/WatchlistApiTest.php` (`never lets one operator touch another's watchlist`).
- **The cross-tenant broadcast guard** — `tests/Feature/Broadcasting/ChannelAuthTest.php` (`refuses a user on someone else's tape channel`).

## 9. Git Flow

`main` is the production/deployable branch. `develop` is the integration
branch. Feature work happens on `feature/*` branches cut from `develop` and
merged back via review — this repo's own history follows that pattern
(`feature/foundation`, `feature/ingest`, `feature/ship`, …).
