# Tapehouse — Claude Code build prompt

Paste the whole of this into Claude Code in plan mode. Review the plan, then approve.

---

Build **Tapehouse**, a Laravel 13 internal operations console for a live market data feed. It ingests price quotes from the Twelve Data API, caches and persists them, rebroadcasts them to browsers over WebSocket, and surfaces feed health to an operator.

Generate every file completely. No `// TODO`, no placeholder bodies, no "implement this" comments. If a file is needed for the app to run, write it in full.

## Non-negotiable stack constraints

This project deliberately uses a stack I would not otherwise choose. Do not substitute.

- **Laravel 13**, PHP 8.3 minimum, `declare(strict_types=1)` in every PHP file
- **PostgreSQL 16** — not MySQL, not SQLite (except in-memory for tests)
- **Redis 7** via **predis** — not phpredis, so Upstash TLS works
- **Laravel Reverb** for broadcasting
- **jQuery 3.7** plus vanilla ES modules for the frontend — **no React, no Vue, no Alpine, no Livewire, no Inertia**
- **SCSS** — not Tailwind, not plain CSS
- **Webpack 5** configured by hand — **not Vite, not Laravel Mix**. Delete Vite entirely from the skeleton.
- **Pest** for tests
- Blade for server-rendered shell only; all data flows through the JSON API

---

## 1. Project setup

```bash
composer create-project laravel/laravel tapehouse
```

Then remove `vite.config.js`, `resources/js/bootstrap.js`'s Vite assumptions, and the `laravel-vite-plugin` dependency.

Composer requires: `laravel/reverb`, `predis/predis`, `ratchet/pawl`, `guzzlehttp/guzzle`, `laravel/pint` (dev), `larastan/larastan` (dev), `pestphp/pest` + `pestphp/pest-plugin-laravel` (dev).

npm requires: `jquery@3.7`, `laravel-echo`, `pusher-js`, `webpack`, `webpack-cli`, `sass`, `sass-loader`, `css-loader`, `mini-css-extract-plugin`, `babel-loader`, `@babel/core`, `@babel/preset-env`, `css-minimizer-webpack-plugin`.

---

## 2. Folder structure

```
app/
  Console/Commands/
    TapeIngest.php              long-running ingest loop
    TapePrune.php               deletes ticks older than 24h
  Events/
    QuotesUpdated.php           batched tick broadcast
    FeedStateChanged.php        driver transition broadcast
    AlertFired.php
  Http/
    Controllers/
      Api/
        SymbolController.php
        WatchlistController.php
        QuoteController.php
        OpsController.php
        FeedEventController.php
        AlertRuleController.php
        AlertEventController.php
      Auth/LoginController.php
      ConsoleController.php     renders the Blade shell
    Requests/
      StoreWatchlistSymbolRequest.php
      StoreAlertRuleRequest.php
      UpdateAlertRuleRequest.php
    Resources/
      SymbolResource.php
      QuoteResource.php
      AlertRuleResource.php
      AlertEventResource.php
      FeedEventResource.php
  Jobs/
    EvaluateAlerts.php
  Models/
    User.php Symbol.php Watchlist.php WatchlistSymbol.php
    Tick.php FeedEvent.php AlertRule.php AlertEvent.php
  Services/
    Upstream/
      UpstreamDriver.php        interface
      WebSocketDriver.php
      PollingDriver.php
      DriverManager.php
      TwelveDataClient.php      thin HTTP wrapper
      DTO/Quote.php             readonly DTO
    Budget/
      CreditBudget.php          Redis token bucket
    Metrics/
      FeedMetrics.php           Redis counters + lag window
    Quotes/
      QuoteCache.php            Redis last-price hash
      TickBuffer.php            batched Postgres writer
      QuoteBroadcaster.php      250ms coalescing broadcaster
  Enums/
    AssetType.php DriverState.php TickSource.php
    AlertCondition.php FeedEventLevel.php
config/tapehouse.php
database/migrations/  (8 migrations, see schema)
database/seeders/
  DatabaseSeeder.php OperatorSeeder.php SymbolSeeder.php
resources/
  js/
    app.js
    modules/
      echo.js tape.js ops.js alerts.js watchlist.js api.js format.js flash.js
  scss/
    app.scss
    _tokens.scss _base.scss _layout.scss _tape.scss _ops.scss
    _alerts.scss _forms.scss _auth.scss
  views/
    layouts/app.blade.php
    console.blade.php
    auth/login.blade.php
routes/
  web.php api.php channels.php console.php
tests/
  Feature/  Unit/
docker/
  nginx/default.conf
  php/php.ini
  supervisor/supervisord.conf
  entrypoint.sh
Dockerfile
docker-compose.yml
webpack.config.js
.env.example
CLAUDE.md
README.md
.github/workflows/ci.yml
```

---

## 3. Database schema

Eight migrations, in this order. Use `numeric` for all money — never float, never string.

**users** — default Laravel.

**symbols** — `id`, `ticker` (string 32, unique), `name` (string 128), `asset_type` (string, enum-cast: `stock|forex|crypto`), `exchange` (string 32, nullable), `currency` (string 8), `is_active` (bool, default true), timestamps. Index on `is_active`.

**watchlists** — `id`, `user_id` (FK cascade), `name` (string 64), timestamps.

**watchlist_symbols** — `id`, `watchlist_id` (FK cascade), `symbol_id` (FK cascade), `position` (int), timestamps. Unique on (`watchlist_id`, `symbol_id`).

**ticks** — `id` (bigIncrements), `symbol_id` (FK cascade), `price` (decimal 18,8), `day_change` (decimal 18,8 nullable), `day_change_pct` (decimal 9,4 nullable), `source` (string, enum-cast: `websocket|polling`), `quoted_at` (timestampTz), `received_at` (timestampTz). Composite index (`symbol_id`, `quoted_at` desc) and index on `quoted_at`. No `updated_at` — ticks are immutable, disable timestamps on the model.

**feed_events** — `id`, `level` (string: `info|warn|error`), `type` (string 64), `message` (text), `context` (jsonb nullable), `occurred_at` (timestampTz). Index on `occurred_at` desc.

**alert_rules** — `id`, `user_id` (FK cascade), `symbol_id` (FK cascade), `condition` (string: `above|below`), `threshold` (decimal 18,8), `is_active` (bool default true), `cooldown_seconds` (int default 60), `last_fired_at` (timestampTz nullable), timestamps. Index on (`is_active`, `symbol_id`).

**alert_events** — `id`, `alert_rule_id` (FK cascade), `price` (decimal 18,8), `fired_at` (timestampTz). Index on `fired_at` desc.

**Seeders:** one operator (`operator@tapehouse.dev` / `tapehouse`), one watchlist with 6 symbols pre-added, and ~40 symbols spanning US equities (AAPL, MSFT, NVDA, TSLA, AMZN, GOOGL, META, JPM, V, XOM…), forex (EUR/USD, GBP/USD, USD/JPY, USD/CHF, AUD/USD…), and crypto (BTC/USD, ETH/USD, SOL/USD, XRP/USD…). Ticker strings must match Twelve Data's format exactly.

---

## 4. The ingest subsystem — the core of this project

Build this first and test it hardest.

### `Quote` DTO
`readonly class Quote` with `string $ticker`, `float $price`, `?float $dayChange`, `?float $dayChangePct`, `TickSource $source`, `CarbonImmutable $quotedAt`, `CarbonImmutable $receivedAt`. Add `lagMs(): int`.

### `UpstreamDriver` interface
```php
interface UpstreamDriver
{
    public function name(): DriverState;
    public function start(array $tickers, callable $onQuote): void;
    public function tick(): void;      // one iteration of work, non-blocking-ish
    public function stop(): void;
    public function isHealthy(): bool;
    public function lastError(): ?string;
}
```

### `WebSocketDriver`
Connects to `wss://ws.twelvedata.com/v1/quotes/price?apikey={key}` using `ratchet/pawl` on a shared ReactPHP loop. On open, sends a `subscribe` action with the ticker list. Parses `price` events into `Quote` DTOs and invokes the callback. Counts consecutive failures. Marks unhealthy on: connection error, auth rejection (`status: error` in the response envelope), or no message received for 90 seconds while markets should be open. Exposes `lastError()`.

Important: Twelve Data's WebSocket requires the Pro plan; the trial allows 8 WebSocket credits. Auth rejection is the **expected** path on an exhausted trial, not an exceptional one. Handle it cleanly and demote — do not throw.

### `PollingDriver`
Calls `GET https://api.twelvedata.com/quote?symbol=A,B,C&apikey=` in batches. **Must acquire a token from `CreditBudget` before every request.** If the bucket is empty, it does not sleep-and-retry the same symbols — it advances `tape:poll:cursor` and polls the next slice on the following pass, so all symbols eventually get covered under starvation. Batch size from config, default 8. Poll interval from config, default 8s.

### `DriverManager`
Owns driver lifecycle.
- Starts on `WebSocketDriver` if `TWELVEDATA_WS_ENABLED=true`, else `PollingDriver`
- Demotes to `PollingDriver` after 3 consecutive WebSocket failures or an auth rejection
- Attempts promotion back on exponential backoff: 60s, 120s, 300s, then every 300s, capped
- Every transition: writes a `feed_events` row, updates `tape:driver:state`, broadcasts `FeedStateChanged`
- Exposes `state(): DriverState`, `since(): CarbonImmutable`, `reconnects(): int`

### `CreditBudget` — Redis token bucket
```php
public function tryConsume(int $tokens = 1): bool
public function available(): int
public function capacity(): int
public function refill(): void      // lazy, called on read
```
Lazy refill: on each call, compute elapsed time since `tape:budget:refilled_at`, add `floor(elapsed_seconds * rate)` tokens capped at capacity, write both keys back. Do the whole read-modify-write in a **Lua script via `EVAL`** so it is atomic under concurrent workers. Capacity and refill rate from config — default capacity 8, rate 8 per 60s.

### `TickBuffer`
Accumulates `Quote` objects in memory. Flushes to Postgres via a single multi-row `insert()` when the buffer reaches 200 rows or 1 second has elapsed since last flush, whichever first. Must flush on shutdown. Never insert one row per tick.

### `QuoteCache`
Writes each quote to `tape:quote:{ticker}` as a Redis hash with 1h TTL. `get(string $ticker): ?Quote` and `many(array $tickers): array`. This is the only read path for current price — the REST snapshot endpoint reads Redis, not Postgres.

### `FeedMetrics`
- `recordLag(int $ms)` — pushes to `tape:metrics:lag`, `LTRIM` to 500
- `lagPercentiles(): array` — returns `['p50' => int, 'p95' => int]`
- `recordTick()` — increments `tape:metrics:ticks_minute:{minute}`, TTL 300s
- `ticksPerMinute(): int`
- `snapshot(): array` — everything the ops panel needs in one call

### `QuoteBroadcaster`
Coalesces quotes into a 250ms window keyed by channel, then dispatches one `QuotesUpdated` event carrying an array of quotes. A single fast symbol must not produce 50 broadcasts per second.

### `TapeIngest` command
```
php artisan tape:ingest {--symbols=} {--driver=}
```
Long-running. Resolves the ticker list from all active watchlist symbols (falls back to `--symbols`). Runs the ReactPHP loop; each pass calls `DriverManager::current()->tick()`, drains quotes into `QuoteCache`, `TickBuffer`, `FeedMetrics`, `QuoteBroadcaster`, and dispatches `EvaluateAlerts` with the batch. Handles `SIGTERM`/`SIGINT` by flushing the buffer and stopping cleanly. Logs to `feed_events` on start, stop, and every driver transition.

---

## 5. Alerts

`EvaluateAlerts` is a queued job (Redis connection) taking an array of `['symbol_id' => int, 'price' => float]`. Loads active `alert_rules` for those symbols. For each: check condition, check `last_fired_at + cooldown_seconds` has passed, and if both hold, create an `alert_events` row, update `last_fired_at`, broadcast `AlertFired`.

Never evaluate alerts inline in the ingest loop. The whole point is that the ingest path stays fast.

---

## 6. Broadcasting

Reverb configured for local (`reverb` driver). Channels in `routes/channels.php`:

- `private-tape.{userId}` — authorised if `(int) $user->id === (int) $userId`. Carries `QuotesUpdated` and `AlertFired`.
- `private-ops` — authorised for any authenticated user. Carries `FeedStateChanged`.

Events implement `ShouldBroadcast`, define `broadcastAs()` with short names (`quotes.updated`, `feed.state`, `alert.fired`), and `broadcastWith()` returning flat arrays — do not broadcast Eloquent models.

---

## 7. REST API

All under `/api`, `auth:web` middleware, `throttle:120,1`. Return API Resources, never raw models.

```
GET    /api/symbols?q=&limit=20
GET    /api/watchlist
POST   /api/watchlist/symbols          {symbol_id}
DELETE /api/watchlist/symbols/{symbolId}
GET    /api/quotes?symbols=AAPL,MSFT   reads QuoteCache
GET    /api/ops/health                 FeedMetrics::snapshot + driver state + queue depth
GET    /api/feed-events?limit=50
GET    /api/alert-rules
POST   /api/alert-rules                {symbol_id, condition, threshold, cooldown_seconds}
PATCH  /api/alert-rules/{id}           {threshold?, is_active?, cooldown_seconds?}
DELETE /api/alert-rules/{id}
GET    /api/alert-events?limit=50
```

Form Requests for all writes. Authorisation on alert rules and watchlist mutations — a user may only touch their own. Use policies, not inline checks.

`GET /api/quotes` is the reconnect snapshot endpoint. It must be fast and must read Redis only.

---

## 8. Frontend

Server-rendered Blade shell, all data over the JSON API and Echo. No SPA router.

**`resources/js/modules/api.js`** — thin fetch wrapper, CSRF header from the meta tag, throws typed errors on non-2xx.

**`resources/js/modules/echo.js`** — configures Laravel Echo with the `reverb` broadcaster, exports the instance. Exposes `onReconnect(cb)` so the tape can refetch its snapshot.

**`resources/js/modules/format.js`** — decimal precision by asset type (stock 2, forex 5, crypto 2 with thousands separators), signed change formatting, relative age from a timestamp.

**`resources/js/modules/flash.js`** — applies the directional flash class to a row and removes it after 600ms. Uses a WeakMap of pending timers keyed by element so rapid consecutive ticks reset rather than stack. Checks `prefers-reduced-motion` and applies the static-border variant instead.

**`resources/js/modules/tape.js`** — renders the watchlist table with jQuery, subscribes to `private-tape.{userId}`, patches only the changed cells (never re-renders the whole table on a tick), drives the flash, runs a 1s interval updating the age column and applying the stale treatment past 30s. On Echo reconnect, calls `GET /api/quotes` and repaints from the snapshot before resuming.

**`resources/js/modules/ops.js`** — polls `GET /api/ops/health` every 3s, subscribes to `private-ops` for immediate driver transitions, renders driver state, the credit bar, lag p50/p95, reconnect count, queue depth, and tails the event log.

**`resources/js/modules/watchlist.js`** — symbol search with 250ms debounce, add and remove.

**`resources/js/modules/alerts.js`** — rule CRUD form and the fired-events list.

**SCSS** — `_tokens.scss` holds the palette and type scale as CSS custom properties. All numerics get `font-variant-numeric: tabular-nums`. Palette:

```
--paper #FBFBFD   --panel #FFFFFF   --ink #0B1220
--muted #6B7789   --rule #E4E8EF    --signal #1A56DB
--up #067A53      --down #C2334D
```

Fonts: Space Grotesk (section labels, wordmark), Inter (body), IBM Plex Mono (all numerics, tickers, timestamps). Load from Google Fonts with `display=swap`.

Colour discipline: `--up`/`--down` appear only on price deltas and flashes; `--signal` only on interactive elements. Nothing else is coloured.

**`webpack.config.js`** — hand-written, not scaffolded. Entry `resources/js/app.js`, output `public/build/[name].[contenthash].js`, `MiniCssExtractPlugin` for SCSS, `babel-loader` with `@babel/preset-env` targeting the last two browser versions, `CssMinimizerPlugin` in production, source maps in development, a manifest JSON written to `public/build/manifest.json` and read by a small Blade helper for cache-busted asset URLs. Scripts: `npm run dev` (watch), `npm run build` (production).

---

## 9. Code quality rules

- `declare(strict_types=1)` at the top of every PHP file
- Constructor property promotion, readonly where the object is immutable
- Return types and parameter types everywhere, including closures. No `mixed` unless genuinely unavoidable, and never `array` without a docblock shape
- Backed enums for every status field — no string literals for `websocket`, `polling`, `above`, `below`, `stock`, etc.
- No business logic in controllers. Controllers validate, delegate to a service, return a Resource
- No facades inside `app/Services/**` — inject dependencies through the constructor so the services are unit-testable without the container
- All config through `config/tapehouse.php`, never `env()` outside config files
- Laravel Pint (Laravel preset) clean
- Larastan level 6 clean
- No N+1: eager-load relationships in every controller that touches them

`config/tapehouse.php` keys: `api_key`, `ws_enabled`, `ws_url`, `rest_url`, `budget.capacity`, `budget.refill_per_minute`, `poll.interval_seconds`, `poll.batch_size`, `broadcast.coalesce_ms`, `ticks.buffer_size`, `ticks.flush_ms`, `ticks.retention_hours`, `driver.failure_threshold`, `driver.promotion_backoff` (array of seconds).

---

## 10. Tests (Pest)

**Unit — these are the ones that matter:**
- `CreditBudget`: consumes down to zero, refuses at zero, refills at the correct rate over simulated time, is atomic under concurrent consumption
- `DriverManager`: demotes after exactly the failure threshold, demotes immediately on auth rejection, promotes on the backoff schedule, records a `feed_event` per transition
- `PollingDriver`: advances the cursor when the budget is exhausted rather than re-polling the same slice; covers all symbols across successive starved passes
- `TickBuffer`: flushes at 200 rows, flushes at the time threshold, flushes on shutdown, inserts as a single query
- `EvaluateAlerts`: fires when the condition is met, does not fire inside the cooldown window, fires again after it, handles `above` and `below`
- `format.js` equivalents are not tested — no JS test runner in scope

**Feature:**
- Each API endpoint: happy path, validation failure, unauthenticated 401
- A user cannot read or mutate another user's alert rules or watchlist (403)
- `GET /api/quotes` reads Redis and does not query Postgres

Use a Postgres test database, and fake Redis with a real Redis instance in CI (the token bucket Lua script needs a real server — do not mock it).

---

## 11. Docker

**`docker-compose.yml`** (development): services `app` (PHP-FPM), `nginx`, `postgres:16`, `redis:7`, `reverb`, `queue`, `ingest`. Named volumes for Postgres and Redis. `app` mounts the source for hot reload.

**`Dockerfile`** (production, multi-stage):
1. `node:22-alpine` — `npm ci`, `npm run build`, emit `public/build`
2. `composer:2` — `composer install --no-dev --optimize-autoloader`
3. `php:8.3-fpm-alpine` — install `pdo_pgsql`, `pcntl`, `sockets`, `opcache`; add nginx and supervisor; copy vendor and built assets; run as a non-root user

**`docker/supervisor/supervisord.conf`** runs four programs: `php-fpm`, `nginx`, `reverb` (`php artisan reverb:start --host=0.0.0.0 --port=8080`), `queue` (`php artisan queue:work --tries=3 --max-time=3600`), and `ingest` (`php artisan tape:ingest`). All with `autorestart=true` and logs to stdout/stderr so Cloud Run captures them.

**`docker/nginx/default.conf`** serves `public/`, proxies PHP to php-fpm, and proxies `/app` and `/apps` to Reverb on 8080 with `Upgrade`/`Connection` headers set for WebSocket.

**`docker/entrypoint.sh`** runs `php artisan migrate --force`, `config:cache`, `route:cache`, `view:cache`, then `exec supervisord`.

**`.env.example`** — every key present with empty or safe-default values, and a comment block at the top explaining how to get a Twelve Data trial key and that WebSocket requires the Pro plan so the app will run in polling mode on a free key.

---

## 12. CI

`.github/workflows/ci.yml`: on push and PR — Postgres 16 and Redis 7 service containers, PHP 8.3, `composer install`, `npm ci && npm run build`, `vendor/bin/pint --test`, `vendor/bin/phpstan analyse`, `php artisan test`.

---

## 13. CLAUDE.md

Write a `CLAUDE.md` at the repo root covering: the stack constraints above and why each was chosen (JD-driven, not preference), the strict-types and enum rules, the "no facades in services" rule, the "controllers delegate, never contain logic" rule, the requirement that ingest-path code never blocks on Postgres or alert evaluation, and the instruction that Pint and Larastan level 6 must pass before any commit.

---

## 14. README

Sections:

1. **What this is** — one paragraph, plus a live URL placeholder for `tapehouse.kevinciang.com`
2. **Why this stack** — states plainly that Laravel, jQuery, Webpack, PostgreSQL and Redis were chosen because they are the Twelve Data stack, and that my daily stack is React/Vite
3. **Architecture** — the dual-driver ingest diagram, the read path (Redis) versus audit path (Postgres) split, and the broadcast coalescing
4. **Performance decisions** — the seven listed in the PRD, one line each
5. **What this project is and isn't** — a short honest section: Redis was learned on this project and is not production experience; last production Laravel was February 2022; Webpack was used because the JD names it; the deployed demo runs on a Twelve Data trial key and falls back to polling when streaming credits are exhausted, which is the intended behaviour
6. **Running locally** — `docker compose up`, seed, credentials
7. **Testing** — how to run, what is covered
8. **Git Flow** — `main`, `develop`, `feature/*`, `release/*`, `hotfix/*`, since the JD asks for Git Flow understanding

Keep section 5 short and factual. It is there so an interviewer finds the gaps stated rather than hidden.

---

## Build order

1. Skeleton, Docker Compose, Postgres and Redis connectivity, Vite removed
2. Migrations, models, enums, seeders
3. `CreditBudget` + its tests — get the Lua script right before anything depends on it
4. `TwelveDataClient`, `Quote` DTO, `PollingDriver` + tests
5. `WebSocketDriver`, `DriverManager` + tests
6. `QuoteCache`, `TickBuffer`, `FeedMetrics`, `QuoteBroadcaster` + tests
7. `TapeIngest` command — verify end to end against a real trial key before building any UI
8. Reverb, events, channels
9. REST API + Resources + policies + feature tests
10. Webpack config, SCSS tokens, Blade shell, login
11. `tape.js`, `flash.js`, `format.js` — the live tape
12. `ops.js` — the ops panel
13. Alerts: job, endpoints, `alerts.js`
14. Production Dockerfile, supervisord, nginx WebSocket proxy
15. CI, CLAUDE.md, README

Steps 1–12 are P0 and must ship. Step 13 is P1 and may be cut. Steps 14–15 always ship.

Verify step 7 works against the live API before writing a single line of frontend code. If the ingest loop does not produce real quotes, nothing downstream matters.
