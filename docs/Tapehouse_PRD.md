# Tapehouse — PRD

**Internal market data operations console**

Demo project built for the Twelve Data application (Senior Software Engineer, Web).

---

## 1. Why this project

The Twelve Data JD is a PHP/Laravel fullstack role, not a React role. The stated requirements are: PHP, JavaScript, HTML, CSS, Laravel, jQuery, PostgreSQL, Redis, REST API, WebSockets, CSS/JS preprocessors, Webpack, Docker, client-side architecture, and performance/scalability work. The team's own description is that they "make a high-quality intranet" — internal tools used by Twelve Data employees.

The last four years of my CV read as React and Angular frontend work. Laravel is real in my history (Maximize Play 2018–2021, ProSpark 2021–2022) but four years stale on paper. This demo exists to close that specific gap and nothing else.

Deliberate choice: build the kind of internal tool Twelve Data itself would need. Tapehouse is an operations console for a live market data feed — it consumes Twelve Data's own public API, so the domain is theirs, not invented.

**Scope discipline:** P0 is one working day. P1 is a second day. If day two runs long, ship P0 and cut P1 — a smaller working thing beats a larger broken thing.

---

## 2. What it is

Tapehouse ingests live price quotes from Twelve Data, stores and caches them, rebroadcasts them to browser clients over WebSocket, and shows an operator whether the feed is healthy and how much of the API budget is being burned.

Three audiences in one screen:

- **The tape** — live prices for symbols on a watchlist
- **The ops panel** — upstream driver state, credit consumption, ingest lag, reconnects, errors
- **Alerts** — threshold rules that fire into a queued job and land in an event log

The ops panel is the part that matters. Anyone can render a price table. Showing that you thought about what happens when the upstream feed degrades is the thing that reads as senior.

---

## 3. The constraint that shapes the architecture

Twelve Data's WebSocket feed is available from the Pro plan onward. The free trial provides 8 API credits per minute (800/day) plus 8 trial WebSocket credits. So a demo can stream a handful of symbols over WebSocket, but cannot rely on it, and REST polling is hard-capped at 8 credits/minute.

This is not a problem to work around. It is the feature.

**Dual-driver ingest with automatic demotion:**

```
UpstreamDriver (interface)
├── WebSocketDriver   primary  — wss://ws.twelvedata.com/v1/quotes/price
└── PollingDriver     fallback — GET /quote, batched, credit-budgeted

DriverManager
  - starts on WebSocketDriver
  - on N consecutive failures or auth rejection → demote to PollingDriver
  - retries promotion on a backoff schedule
  - every transition written to feed_events + broadcast to the ops panel
```

**Credit budgeting** is a Redis token bucket. The PollingDriver must acquire a token before every upstream request. Bucket refills at the configured rate (default 8/min). When the bucket is empty, symbols are polled on a rotating priority queue rather than dropped — watchlist symbols in view get priority over background symbols.

This one design decision covers four JD bullets at once: Redis, REST API, WebSockets, and "spot and resolve performance and scalability issues."

---

## 4. Stack

Chosen to match the JD, not my usual preferences. This is stated in the README and in the application email — using their stack deliberately is part of the point.

| Layer | Choice | Why |
|---|---|---|
| Framework | Laravel 13 (PHP 8.3) | Current major, released March 2026 |
| Database | PostgreSQL 16 | Named in JD |
| Cache / bucket / metrics | Redis 7 | Named in JD |
| Broadcast | Laravel Reverb | First-party WS server, no Pusher account |
| Frontend | jQuery 3.7, vanilla ES modules | Named in JD |
| Styles | SCSS | JD asks for CSS preprocessors |
| Build | Webpack 5 | Named in JD explicitly — **not Vite** |
| Queue | Redis-backed Laravel queue | Alert evaluation off the ingest path |
| Containers | Docker + Compose | Named in JD |
| CI | GitHub Actions | Pint, Larastan, Pest, build |

**Deliberately not used:** React, Vue, Tailwind, Vite, Inertia. The whole value of this demo is that it is their stack.

---

## 5. Features

### P0 — day one, ships no matter what

**Auth**
Single seeded operator account, Laravel session auth. No registration, no password reset. Login page and logout only.

**Symbols and watchlist**
- Seeded symbol universe (~40 rows: US equities, forex pairs, crypto) from a static seeder — no upstream call needed to browse
- One watchlist per user, add/remove symbols, reorder by drag is out of scope
- Symbol search filters the seeded universe server-side

**Ingest**
- `php artisan tape:ingest` — long-running command, supervised
- `UpstreamDriver` interface, `WebSocketDriver` + `PollingDriver` implementations
- `DriverManager` with demotion and backoff promotion
- Redis token bucket credit budget, enforced by `PollingDriver`
- Every tick written to Redis last-price hash and appended to `ticks` in Postgres via buffered batch insert (flush every 1s or 200 rows, whichever first)

**Live tape**
- Table of watchlist symbols: symbol, name, last, change, change %, updated-at
- Laravel Echo + pusher-js subscribed to Reverb, private channel per user watchlist
- Price cell flashes directional on update, decays over ~600ms
- Reconnect handling: on socket reopen, refetch full snapshot via REST before resuming stream

**Ops panel**
- Current driver (websocket / polling) and time in state
- Credits consumed this minute vs budget, as a bar
- Ingest lag p50 / p95 in ms, measured from upstream tick timestamp to receipt
- Reconnect count, last error message and time
- Live event log of driver transitions, tailing the last 50 `feed_events`

**REST API**
All JSON, session-authenticated, `/api/*`:
```
GET    /api/symbols?q=
GET    /api/watchlist
POST   /api/watchlist/symbols       { symbol_id }
DELETE /api/watchlist/symbols/{id}
GET    /api/quotes?symbols=AAPL,MSFT
GET    /api/ops/health
GET    /api/feed-events?limit=50
```

### P1 — day two, cut if time runs out

**Alert rules**
- Create a rule: symbol, condition (`above` / `below`), threshold, active toggle
- On each tick batch, dispatch `EvaluateAlerts` to the Redis queue — never evaluate inline on the ingest path
- Firing writes an `alert_events` row and broadcasts to the client
- Cooldown per rule (default 60s) so a symbol oscillating around a threshold does not spam
- Alerts list with fired-at history

```
GET    /api/alert-rules
POST   /api/alert-rules
PATCH  /api/alert-rules/{id}
DELETE /api/alert-rules/{id}
GET    /api/alert-events?limit=50
```

**Symbol detail drawer**
- Sparkline from the last 200 ticks in Postgres, drawn on canvas — no chart library
- Recent tick table

### Explicitly out of scope

Multi-user tenancy, registration, historical backfill, candlestick charts, indicators, portfolio/PnL, mobile app, i18n, dark mode toggle, admin roles.

---

## 6. Data model

```sql
users                 id, name, email, password, timestamps

symbols               id, ticker, name, asset_type(enum: stock|forex|crypto),
                      exchange, currency, is_active, timestamps
                      unique(ticker)

watchlists            id, user_id, name, timestamps

watchlist_symbols     id, watchlist_id, symbol_id, position, timestamps
                      unique(watchlist_id, symbol_id)

ticks                 id(bigserial), symbol_id, price(numeric 18,8),
                      day_change(numeric 18,8) null, day_change_pct(numeric 9,4) null,
                      source(enum: websocket|polling),
                      quoted_at(timestamptz), received_at(timestamptz)
                      index(symbol_id, quoted_at desc)
                      index(quoted_at)

feed_events           id, level(enum: info|warn|error), type, message,
                      context(jsonb), occurred_at(timestamptz)
                      index(occurred_at desc)

alert_rules           id, user_id, symbol_id, condition(enum: above|below),
                      threshold(numeric 18,8), is_active, cooldown_seconds,
                      last_fired_at null, timestamps

alert_events          id, alert_rule_id, price(numeric 18,8),
                      fired_at(timestamptz)
                      index(fired_at desc)
```

**Redis keys**

```
tape:quote:{ticker}              hash   last price snapshot, TTL 1h
tape:budget:tokens               string token bucket count
tape:budget:refilled_at          string last refill timestamp
tape:metrics:lag                 list   rolling window of last 500 lag samples (ms)
tape:metrics:ticks_minute:{min}  string counter, TTL 5m
tape:driver:state                hash   driver, since, reconnects, last_error
tape:poll:cursor                 string rotating polling priority cursor
```

`ticks` is append-heavy. Buffered batch insert, and a `tape:prune` scheduled command deleting ticks older than 24 hours keeps the demo database small.

---

## 7. Performance decisions worth defending in interview

These are the things to be ready to talk about. Each is a real decision with a real tradeoff.

1. **Buffered batch insert, not insert-per-tick.** At 8 symbols the difference is nothing; the point is that the write path is designed for the case where it is 8,000. Flush on 200 rows or 1 second.

2. **Redis as the read path, Postgres as the audit path.** The tape reads last-price from a Redis hash. Postgres is never in the hot path for current price. Postgres only serves the sparkline and history.

3. **Alert evaluation is queued, never inline.** A slow alert rule must not be able to stall ingest. Queue depth is visible on the ops panel.

4. **Broadcast batching.** Ticks are coalesced into one broadcast per 250ms window per channel rather than one event per tick. Prevents a fast-moving symbol from saturating the socket.

5. **Token bucket over naive rate limiting.** A fixed sleep wastes budget when idle and overruns when bursty. The bucket allows burst up to capacity and self-throttles.

6. **Snapshot-then-stream on reconnect.** A client that reconnects has a gap. Refetching a REST snapshot before resuming the stream closes it. Without this the tape silently shows stale prices after any network blip.

7. **Rotating priority cursor when budget-starved.** Under polling with 8 credits/min and 20 watchlist symbols, you cannot poll everything every minute. Symbols in the viewport rotate first.

---

## 8. Honesty constraints

These carry into the README and the application email. Do not overstate.

- **Redis is new to me.** I have not used Redis in production. This project is where I learned it. The token bucket and metrics counters are mine and I can explain them line by line, but I will not claim production Redis experience.
- **Laravel is real but stale.** Last production Laravel was February 2022 at ProSpark. This project is current-Laravel, and I will say plainly that I have refreshed rather than pretend continuity.
- **Webpack over Vite.** My daily build tool is Vite. Webpack is used here because the JD names it. Configured from scratch, not copied from a starter.
- **jQuery.** Used at Maximize Play and ProSpark. Not what I have reached for in four years, used here deliberately.

The README states all four in a short "What this project is and isn't" section. Naming a gap before an interviewer finds it is worth more than hiding it.

---

## 9. Deployment

Target: `tapehouse.kevinciang.com`

**Cloud Run** (primary, matches existing portfolio pattern):
- Single container: nginx + php-fpm + Reverb + queue worker + ingest command, under supervisord
- `--min-instances=1` — required, Reverb and the ingest loop must stay alive
- `--session-affinity` — required for WebSocket
- `--timeout=3600`
- Postgres: existing Supabase instance
- Redis: Upstash free tier over TLS via predis

**Fallback if Cloud Run WebSocket proves fiddly:** Railway or Fly.io handle persistent processes with less configuration. Do not burn day two on infrastructure — if Cloud Run resists for more than 90 minutes, switch.

A demo API key is committed to `.env.example` as an empty value with instructions. The deployed instance runs on my own trial key, and degrades to polling when the WebSocket trial credits are exhausted — which is the intended behaviour and is stated on the login screen.

---

## 10. Success criteria

The demo has done its job if a Twelve Data engineer opening the repo can see, within two minutes:

- Current Laravel, structured properly, not a tutorial layout
- Real PostgreSQL schema decisions, not `string` columns for money
- Redis used for something that needs Redis, not as a cache ornament
- A WebSocket implementation on both ends
- A Webpack config that was written, not scaffolded
- A Dockerfile that runs multiple processes correctly
- Tests on the logic that matters — token bucket, driver demotion, alert evaluation
- A README that says what is not finished

And if the live URL loads and prices move.
