# Tapehouse Plan 1 — Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stand up a Laravel 13 application on PostgreSQL and Redis with the complete Tapehouse schema, enums, models and seed data, verified green under Pest, Pint and Larastan.

**Architecture:** A slim Laravel 13 skeleton scaffolded at the repository root with Vite and Tailwind stripped out entirely. PostgreSQL holds the audit path (symbols, watchlists, ticks, events, alert rules); Redis holds cache and queue. Every status field is a backed PHP enum. No application logic ships in this plan — it delivers the substrate that Plans 2–5 build on.

**Tech Stack:** PHP 8.4 (Homebrew, keg-only), Laravel 13.8, PostgreSQL 16, Redis 8 via predis, Pest 5, Pint, Larastan level 6.

## Global Constraints

Every task below inherits these. They come from `docs/superpowers/specs/2026-08-10-tapehouse-design.md` §8.

- `declare(strict_types=1)` at the top of **every** PHP file, including migrations, factories, seeders and tests.
- Backed PHP enums for every status field. No string literals for `websocket`, `polling`, `above`, `below`, `stock` etc.
- All money is `decimal(18,8)`. Never float, never string.
- All configuration through `config/tapehouse.php`. Never call `env()` outside a file in `config/`.
- No facades inside `app/Services/**` — constructor injection only. (No services exist in this plan; the rule is stated because Task 3 creates the config they read.)
- Constructor property promotion; `readonly` where the object is immutable. Full parameter and return types, including closures.
- Pint (Laravel preset + `declare_strict_types`) and Larastan level 6 must pass before every commit.
- Git Flow: work on `feature/foundation`, branched from `develop`. Commit at the end of each task.
- **Git commands must run with the sandbox disabled** — this environment denies writes to `.git` otherwise.

## Deviations from the spec introduced by this plan

| # | Change | Why |
|---|---|---|
| D9 | `symbols` gains `price_decimals` (unsigned tiny int) | Precision cannot be derived from `asset_type`. The design mock renders `XAU/USD` at 2 decimals though it is a forex-style pair, and real JPY pairs use 3 where other pairs use 5. A per-symbol column is the only thing that renders the designed tape correctly. |
| D10 | `docker-compose.yml` moves to Plan 5 | The Docker daemon is not running and the chosen toolchain is native Homebrew. A task that cannot be verified does not belong in a plan; Compose is written and exercised in Plan 5 alongside the production Dockerfile. |
| D11 | Default `create_cache_table` migration deleted; `create_jobs_table` kept | Cache is Redis, so the table is dead weight. The jobs migration is kept solely for `failed_jobs`, which stays useful with a Redis queue. |
| D12 | `SPY` seeded as `stock`, `XAU/USD` as `forex` | `AssetType` is specified as `stock\|forex\|crypto`. SPY is an equity ETF and XAU/USD quotes like a forex pair, so neither warrants widening the enum. D9 handles their display precision. |

## File Structure

**Created by scaffolding (Task 1), then owned by us:**

| Path | Responsibility |
|---|---|
| `composer.json` | PHP dependencies |
| `package.json` | Rewritten from scratch — no Vite, no Tailwind |
| `bootstrap/app.php` | Routing, middleware, exception config |
| `.env` / `.env.example` | Postgres + Redis + Twelve Data wiring |

**Created by this plan:**

| Path | Responsibility |
|---|---|
| `config/tapehouse.php` | Every Tapehouse tunable; the only reader of Tapehouse `env()` values |
| `pint.json` | Formatting rules |
| `phpstan.neon` | Larastan level 6 |
| `app/Enums/AssetType.php` | `stock\|forex\|crypto` + default display precision |
| `app/Enums/DriverState.php` | `websocket\|polling\|simulated\|stopped` |
| `app/Enums/TickSource.php` | `websocket\|polling\|simulated` |
| `app/Enums/AlertMetric.php` | `price\|change_pct` |
| `app/Enums/AlertCondition.php` | `above\|below` + `isSatisfiedBy()` |
| `app/Enums/FeedEventLevel.php` | `info\|warn\|error` |
| `database/migrations/*_create_symbols_table.php` | Symbol universe |
| `database/migrations/*_create_watchlists_table.php` | One watchlist per user |
| `database/migrations/*_create_watchlist_symbols_table.php` | Ordered membership |
| `database/migrations/*_create_ticks_table.php` | Append-heavy audit path |
| `database/migrations/*_create_feed_events_table.php` | Driver transition log |
| `database/migrations/*_create_alert_rules_table.php` | Threshold rules |
| `database/migrations/*_create_alert_events_table.php` | Fired history |
| `app/Models/{Symbol,Watchlist,WatchlistSymbol,Tick,FeedEvent,AlertRule,AlertEvent}.php` | Eloquent models, casts, relationships |
| `database/factories/*Factory.php` | Test data |
| `database/seeders/{OperatorSeeder,SymbolSeeder,WatchlistSeeder}.php` | Demo data |

---

### Task 1: Toolchain and Laravel 13 skeleton

**Files:**
- Create: `composer.json`, `artisan`, `bootstrap/app.php`, `config/*`, `routes/*`, `.env`, `.env.example` (all via scaffold)
- Create: `package.json` (rewritten)
- Delete: `vite.config.js`, `resources/css/app.css`, `database/migrations/0001_01_01_000001_create_cache_table.php`, `database/database.sqlite`
- Modify: `.gitignore` (merge scaffold's into ours)

**Interfaces:**
- Consumes: nothing
- Produces: a bootable Laravel 13 app on PostgreSQL. `php artisan migrate` succeeds. Later tasks assume `php` resolves to 8.4 and `DB_CONNECTION=pgsql`.

- [ ] **Step 1: Install PHP 8.4 and Composer**

```bash
brew install php@8.4 composer
```

`php@8.4` is keg-only, so it is not linked into `PATH`. Add it for this shell and persist it:

```bash
export PATH="/opt/homebrew/opt/php@8.4/bin:$PATH"
echo 'export PATH="/opt/homebrew/opt/php@8.4/bin:$PATH"' >> ~/.zshrc
```

- [ ] **Step 2: Verify the PHP build has the extensions the project needs**

```bash
php -v
php -m | grep -E '^(pdo_pgsql|pcntl|sockets|mbstring|openssl)$'
```

Expected: `PHP 8.4.x`, and all five extensions listed. `pdo_pgsql` drives Postgres, `pcntl` lets `tape:ingest` trap SIGTERM, `sockets` backs the ReactPHP loop. If any is missing, stop — Plan 2 cannot proceed without them.

- [ ] **Step 3: Start Redis and create the databases**

```bash
brew services start redis
redis-cli ping
createdb tapehouse
createdb tapehouse_test
psql -l | grep tapehouse
```

Expected: `PONG`, and both `tapehouse` and `tapehouse_test` listed.

- [ ] **Step 4: Scaffold Laravel 13 into a temporary directory**

`composer create-project` refuses a non-empty target and our root already holds `docs/` and `.git/`, so scaffold elsewhere and move in.

```bash
composer create-project laravel/laravel:^13.8 /tmp/tapehouse-skel --no-scripts
```

- [ ] **Step 5: Move the skeleton in, merging rather than replacing**

```bash
cd /Users/kevinciang/Documents/Projects/tapehouse
cat /tmp/tapehouse-skel/.gitignore >> .gitignore
rm /tmp/tapehouse-skel/.gitignore
rsync -a /tmp/tapehouse-skel/ .
rm -rf /tmp/tapehouse-skel
```

Then dedupe `.gitignore` so the merged file has no repeated lines, keeping first occurrences:

```bash
awk '!seen[$0]++ || $0==""' .gitignore > .gitignore.tmp && mv .gitignore.tmp .gitignore
```

- [ ] **Step 6: Strip Vite and Tailwind**

```bash
rm -f vite.config.js resources/css/app.css database/database.sqlite
rm -f database/migrations/0001_01_01_000001_create_cache_table.php
```

Replace `package.json` entirely. Webpack itself arrives in Plan 4; this file exists now only so nothing references Vite:

```json
{
    "$schema": "https://www.schemastore.org/package.json",
    "private": true,
    "scripts": {
        "build": "echo \"webpack config arrives in plan 4\" && exit 0"
    }
}
```

Note the removal of `"type": "module"` — the Webpack config in Plan 4 is CommonJS.

- [ ] **Step 7: Point the app at PostgreSQL and Redis**

Edit `.env`, changing these keys (leave everything else at scaffold defaults):

```
APP_NAME=Tapehouse

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=tapehouse
DB_USERNAME=kevinciang
DB_PASSWORD=

CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=database

REDIS_CLIENT=predis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=null
```

`REDIS_CLIENT=predis` is required by the spec so Upstash TLS works in production. `SESSION_DRIVER=database` reuses the `sessions` table the default users migration already creates.

Apply the same edits to `.env.example`, but leave `DB_USERNAME` as `tapehouse` and `DB_PASSWORD` empty there, and delete the `VITE_APP_NAME` line from both files.

- [ ] **Step 8: Generate the key and migrate**

```bash
php artisan key:generate
php artisan migrate
```

Expected: three migrations run (users, jobs — cache was deleted). No SQLite file is created.

- [ ] **Step 9: Verify the app boots against Postgres and Redis**

```bash
php artisan about --only=environment,drivers
php artisan tinker --execute="echo DB::connection()->getDriverName(), PHP_EOL;"
```

Expected: `Database: pgsql`, `Cache: redis`, `Queue: redis`, and tinker prints `pgsql`.

- [ ] **Step 10: Commit**

```bash
git checkout -b feature/foundation
git add -A
git commit -m "chore: scaffold Laravel 13 on postgres and redis, strip vite

Scaffolds the slim Laravel 13.8 skeleton at the repository root and removes
Vite, Tailwind and the sqlite default. Cache and queue move to Redis via
predis; sessions stay on the existing database table.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 2: Dependencies and quality gates

**Files:**
- Modify: `composer.json`
- Create: `pint.json`, `phpstan.neon`, `tests/Pest.php`
- Modify: `phpunit.xml`

**Interfaces:**
- Consumes: the bootable app from Task 1
- Produces: `vendor/bin/pest`, `vendor/bin/pint`, `vendor/bin/phpstan` all green. Later tasks run `vendor/bin/pest` to verify their own tests.

- [ ] **Step 1: Install runtime dependencies**

```bash
composer require laravel/reverb predis/predis ratchet/pawl guzzlehttp/guzzle
```

These are all Plan 2 and 3 dependencies, installed now so the lockfile settles once.

- [ ] **Step 2: Swap PHPUnit for Pest 5 and add Larastan**

```bash
composer remove --dev phpunit/phpunit
composer require --dev pestphp/pest pestphp/pest-plugin-laravel larastan/larastan --with-all-dependencies
```

The skeleton's `composer.json` already allows the `pestphp/pest-plugin` plugin, so this needs no interactive confirmation.

- [ ] **Step 3: Initialise Pest**

```bash
./vendor/bin/pest --init
```

- [ ] **Step 4: Configure the test database**

Edit `phpunit.xml`. Inside `<php>`, set the connection to the Postgres test database rather than the in-memory SQLite the scaffold assumes:

```xml
<env name="DB_CONNECTION" value="pgsql"/>
<env name="DB_DATABASE" value="tapehouse_test"/>
<env name="CACHE_STORE" value="array"/>
<env name="QUEUE_CONNECTION" value="sync"/>
<env name="REDIS_CLIENT" value="predis"/>
```

Delete any existing `DB_CONNECTION` or `DB_DATABASE` env lines so there are no duplicates. Postgres is used rather than SQLite because the schema relies on `jsonb` and `timestamptz`, which SQLite does not model.

- [ ] **Step 5: Enable RefreshDatabase for feature tests**

Replace `tests/Pest.php` with:

```php
<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');
```

- [ ] **Step 6: Configure Pint**

Create `pint.json`:

```json
{
    "preset": "laravel",
    "rules": {
        "declare_strict_types": true
    }
}
```

The `declare_strict_types` rule is what makes the global constraint enforceable rather than aspirational.

- [ ] **Step 7: Configure Larastan at level 6**

Create `phpstan.neon`:

```neon
includes:
    - vendor/larastan/larastan/extension.neon

parameters:
    level: 6
    paths:
        - app
        - config
        - database
        - routes
        - tests
```

The spec mandates level 6 and nothing more. Do not add `checkModelProperties`
— it demands property docblocks on every model and generates a large volume
of findings unrelated to anything this project cares about.

- [ ] **Step 8: Run all three gates**

```bash
vendor/bin/pint
vendor/bin/phpstan analyse --memory-limit=512M
vendor/bin/pest
```

Expected: Pint reports files fixed (it will add `declare(strict_types=1)` across the scaffold), PHPStan reports `[OK] No errors`, Pest reports the two example tests passing.

If PHPStan flags anything in the untouched scaffold, fix it rather than lowering the level — the level is a spec constraint.

- [ ] **Step 9: Commit**

```bash
git add -A
git commit -m "chore: add reverb, predis, pawl, pest 5, pint and larastan

Installs the runtime dependencies Plans 2 and 3 need, replaces PHPUnit with
Pest 5, and wires the quality gates. Pint enforces declare(strict_types=1)
and Larastan runs at level 6, both spec requirements. Tests run against a
real PostgreSQL database because the schema uses jsonb and timestamptz.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 3: Tapehouse configuration

**Files:**
- Create: `config/tapehouse.php`
- Test: `tests/Unit/TapehouseConfigTest.php`
- Modify: `.env`, `.env.example`

**Interfaces:**
- Consumes: nothing from earlier tasks
- Produces: `config('tapehouse.*')`. Plan 2 reads `budget.capacity`, `budget.refill_per_minute`, `poll.interval_seconds`, `poll.batch_size`, `driver.failure_threshold`, `driver.promotion_backoff`, `stale.{websocket,polling,simulated}`, `ticks.*`, `broadcast.coalesce_ms`, `simulator.*`.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/TapehouseConfigTest.php`:

```php
<?php

declare(strict_types=1);

it('exposes every key the ingest subsystem depends on', function (string $key): void {
    expect(config("tapehouse.{$key}"))->not->toBeNull();
})->with([
    'api_key',
    'ws_enabled',
    'ws_url',
    'rest_url',
    'budget.capacity',
    'budget.refill_per_minute',
    'poll.interval_seconds',
    'poll.batch_size',
    'broadcast.coalesce_ms',
    'ticks.buffer_size',
    'ticks.flush_ms',
    'ticks.retention_hours',
    'driver.failure_threshold',
    'driver.promotion_backoff',
    'stale.websocket',
    'stale.polling',
    'stale.simulated',
    'simulator.enabled',
    'simulator.interval_ms',
]);

it('defaults the credit budget to the twelve data trial allowance', function (): void {
    expect(config('tapehouse.budget.capacity'))->toBe(8)
        ->and(config('tapehouse.budget.refill_per_minute'))->toBe(8);
});

it('treats polling as stale later than websocket', function (): void {
    expect(config('tapehouse.stale.polling'))
        ->toBeGreaterThan(config('tapehouse.stale.websocket'));
});

it('pins the promotion backoff schedule', function (): void {
    $backoff = config('tapehouse.driver.promotion_backoff');

    expect($backoff)->toBe([60, 120, 300]);
});

it('defaults the simulated driver to off, so it never runs unasked', function (): void {
    putenv('TAPEHOUSE_SIMULATOR_ENABLED');
    unset($_ENV['TAPEHOUSE_SIMULATOR_ENABLED'], $_SERVER['TAPEHOUSE_SIMULATOR_ENABLED']);

    expect((require base_path('config/tapehouse.php'))['simulator']['enabled'])->toBeFalse();
});
```

The third test is the one that encodes decision D3 — staleness is driver-relative, so a reviewer changing it back to a single threshold breaks a test that explains why.

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/pest tests/Unit/TapehouseConfigTest.php`
Expected: FAIL — every dataset case returns `null` because `config/tapehouse.php` does not exist.

- [ ] **Step 3: Write the config file**

Create `config/tapehouse.php`:

```php
<?php

declare(strict_types=1);

return [

    'api_key' => env('TWELVEDATA_API_KEY', ''),

    'ws_enabled' => (bool) env('TWELVEDATA_WS_ENABLED', true),

    'ws_url' => env('TWELVEDATA_WS_URL', 'wss://ws.twelvedata.com/v1/quotes/price'),

    'rest_url' => env('TWELVEDATA_REST_URL', 'https://api.twelvedata.com'),

    /*
     | Redis token bucket. Twelve Data charges one credit per symbol, not per
     | request, so a batch of eight symbols costs eight tokens.
     */
    'budget' => [
        'capacity' => (int) env('TAPEHOUSE_BUDGET_CAPACITY', 8),
        'refill_per_minute' => (int) env('TAPEHOUSE_BUDGET_REFILL_PER_MINUTE', 8),
    ],

    'poll' => [
        'interval_seconds' => (int) env('TAPEHOUSE_POLL_INTERVAL_SECONDS', 8),
        'batch_size' => (int) env('TAPEHOUSE_POLL_BATCH_SIZE', 8),
    ],

    'broadcast' => [
        'coalesce_ms' => (int) env('TAPEHOUSE_BROADCAST_COALESCE_MS', 250),
    ],

    'ticks' => [
        'buffer_size' => (int) env('TAPEHOUSE_TICKS_BUFFER_SIZE', 200),
        'flush_ms' => (int) env('TAPEHOUSE_TICKS_FLUSH_MS', 1000),
        'retention_hours' => (int) env('TAPEHOUSE_TICKS_RETENTION_HOURS', 24),
    ],

    'driver' => [
        'failure_threshold' => (int) env('TAPEHOUSE_DRIVER_FAILURE_THRESHOLD', 3),
        'promotion_backoff' => [60, 120, 300],
    ],

    /*
     | Seconds without a tick before a symbol reads as stale. Driver-relative,
     | because a polling feed on a trial key legitimately refreshes far slower
     | than a streaming one — staleness should mean the feed is behind, not
     | that the account is on a free plan.
     */
    'stale' => [
        'websocket' => (int) env('TAPEHOUSE_STALE_WEBSOCKET', 30),
        'polling' => (int) env('TAPEHOUSE_STALE_POLLING', 90),
        'simulated' => (int) env('TAPEHOUSE_STALE_SIMULATED', 30),
    ],

    /*
     | The simulated driver exists so the tape has enough ticks to exercise the
     | flash during development and in the deployed demo. It always reports
     | itself as `simulated` in the ops panel; it never masquerades as live.
     */
    'simulator' => [
        'enabled' => (bool) env('TAPEHOUSE_SIMULATOR_ENABLED', false),
        'interval_ms' => (int) env('TAPEHOUSE_SIMULATOR_INTERVAL_MS', 620),
    ],

];
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/pest tests/Unit/TapehouseConfigTest.php`
Expected: PASS — 19 dataset cases plus the four behavioural tests.

- [ ] **Step 5: Add the Tapehouse keys to both env files**

Append to `.env` and `.env.example`:

```
TWELVEDATA_API_KEY=
TWELVEDATA_WS_ENABLED=true
TAPEHOUSE_SIMULATOR_ENABLED=false
```

In `.env.example` only, precede them with this comment block:

```
# Twelve Data credentials. Get a free trial key at https://twelvedata.com/pricing
# The WebSocket feed requires the Pro plan; on a trial key the socket is
# rejected at auth and the app demotes to REST polling, which is the intended
# behaviour and is visible in the ops panel. Polling is capped at 8 credits per
# minute and Twelve Data bills one credit per symbol, not per request.
# Set TAPEHOUSE_SIMULATOR_ENABLED=true to run the tape on generated ticks
# instead — the ops panel reports the driver as `simulated` when you do.
```

Put the real key in `.env` only. `.env` is gitignored; `.env.example` must stay empty.

- [ ] **Step 6: Run the gates and commit**

```bash
vendor/bin/pint
vendor/bin/phpstan analyse --memory-limit=512M
vendor/bin/pest
git add -A
git commit -m "feat: add tapehouse configuration

Every tunable the ingest subsystem reads, with the credit budget defaulting
to the Twelve Data trial allowance of 8 per minute. Staleness thresholds are
per driver so a slow polling feed is not mislabelled as a broken one.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 4: Enums

**Files:**
- Create: `app/Enums/{AssetType,DriverState,TickSource,AlertMetric,AlertCondition,FeedEventLevel}.php`
- Test: `tests/Unit/EnumsTest.php`

**Interfaces:**
- Consumes: nothing
- Produces:
  - `AssetType::{Stock,Forex,Crypto}`, `->defaultDecimals(): int`
  - `DriverState::{WebSocket,Polling,Simulated,Stopped}`, `->isLive(): bool`, `->staleThreshold(): int`
  - `TickSource::{WebSocket,Polling,Simulated}`
  - `AlertMetric::{Price,ChangePct}`
  - `AlertCondition::{Above,Below}`, `->isSatisfiedBy(float $value, float $threshold): bool`
  - `FeedEventLevel::{Info,Warn,Error}`

  Plan 2 casts model columns to these and calls `staleThreshold()` and `isSatisfiedBy()`.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/EnumsTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\AlertCondition;
use App\Enums\AlertMetric;
use App\Enums\AssetType;
use App\Enums\DriverState;
use App\Enums\FeedEventLevel;
use App\Enums\TickSource;

it('backs every enum with the string stored in the database', function (): void {
    expect(AssetType::Stock->value)->toBe('stock')
        ->and(AssetType::Forex->value)->toBe('forex')
        ->and(AssetType::Crypto->value)->toBe('crypto')
        ->and(DriverState::WebSocket->value)->toBe('websocket')
        ->and(DriverState::Polling->value)->toBe('polling')
        ->and(DriverState::Simulated->value)->toBe('simulated')
        ->and(DriverState::Stopped->value)->toBe('stopped')
        ->and(TickSource::WebSocket->value)->toBe('websocket')
        ->and(AlertMetric::Price->value)->toBe('price')
        ->and(AlertMetric::ChangePct->value)->toBe('change_pct')
        ->and(AlertCondition::Above->value)->toBe('above')
        ->and(AlertCondition::Below->value)->toBe('below')
        ->and(FeedEventLevel::Warn->value)->toBe('warn');
});

it('gives each asset type a default display precision', function (): void {
    expect(AssetType::Stock->defaultDecimals())->toBe(2)
        ->and(AssetType::Forex->defaultDecimals())->toBe(5)
        ->and(AssetType::Crypto->defaultDecimals())->toBe(2);
});

it('knows which driver states are producing quotes', function (): void {
    expect(DriverState::WebSocket->isLive())->toBeTrue()
        ->and(DriverState::Polling->isLive())->toBeTrue()
        ->and(DriverState::Simulated->isLive())->toBeTrue()
        ->and(DriverState::Stopped->isLive())->toBeFalse();
});

it('reads its stale threshold from config, per driver', function (): void {
    config()->set('tapehouse.stale.websocket', 30);
    config()->set('tapehouse.stale.polling', 90);

    expect(DriverState::WebSocket->staleThreshold())->toBe(30)
        ->and(DriverState::Polling->staleThreshold())->toBe(90);
});

it('reports a stopped feed as never stale', function (): void {
    expect(DriverState::Stopped->staleThreshold())->toBe(PHP_INT_MAX);
});

it('evaluates the above condition', function (): void {
    expect(AlertCondition::Above->isSatisfiedBy(230.01, 230.00))->toBeTrue()
        ->and(AlertCondition::Above->isSatisfiedBy(230.00, 230.00))->toBeFalse()
        ->and(AlertCondition::Above->isSatisfiedBy(229.99, 230.00))->toBeFalse();
});

it('evaluates the below condition', function (): void {
    expect(AlertCondition::Below->isSatisfiedBy(-2.01, -2.00))->toBeTrue()
        ->and(AlertCondition::Below->isSatisfiedBy(-2.00, -2.00))->toBeFalse()
        ->and(AlertCondition::Below->isSatisfiedBy(-1.99, -2.00))->toBeFalse();
});
```

The strict-inequality cases matter: a rule at exactly its threshold must not fire, otherwise a price resting on a round number retriggers on every tick until the cooldown absorbs it.

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/pest tests/Unit/EnumsTest.php`
Expected: FAIL with `Class "App\Enums\AssetType" not found`.

- [ ] **Step 3: Write the enums**

`app/Enums/AssetType.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum AssetType: string
{
    case Stock = 'stock';
    case Forex = 'forex';
    case Crypto = 'crypto';

    /**
     * Fallback display precision. Individual symbols override this via
     * symbols.price_decimals, because XAU/USD quotes to 2 places while
     * most forex pairs quote to 5.
     */
    public function defaultDecimals(): int
    {
        return match ($this) {
            self::Stock, self::Crypto => 2,
            self::Forex => 5,
        };
    }
}
```

`app/Enums/DriverState.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum DriverState: string
{
    case WebSocket = 'websocket';
    case Polling = 'polling';
    case Simulated = 'simulated';
    case Stopped = 'stopped';

    public function isLive(): bool
    {
        return $this !== self::Stopped;
    }

    /**
     * Seconds without a tick before a symbol reads as stale. A polling feed on
     * a trial key refreshes far slower than a streaming one, so a single fixed
     * threshold would mark the whole tape stale purely because of the plan.
     */
    public function staleThreshold(): int
    {
        return match ($this) {
            self::WebSocket => (int) config('tapehouse.stale.websocket'),
            self::Polling => (int) config('tapehouse.stale.polling'),
            self::Simulated => (int) config('tapehouse.stale.simulated'),
            self::Stopped => PHP_INT_MAX,
        };
    }
}
```

`app/Enums/TickSource.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum TickSource: string
{
    case WebSocket = 'websocket';
    case Polling = 'polling';
    case Simulated = 'simulated';
}
```

`app/Enums/AlertMetric.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum AlertMetric: string
{
    case Price = 'price';
    case ChangePct = 'change_pct';

    public function label(): string
    {
        return match ($this) {
            self::Price => 'price',
            self::ChangePct => 'change%',
        };
    }
}
```

`app/Enums/AlertCondition.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum AlertCondition: string
{
    case Above = 'above';
    case Below = 'below';

    /**
     * Strict comparison on purpose: a rule sitting exactly on its threshold
     * must not fire, or a price resting on a round number retriggers on every
     * tick until the cooldown absorbs it.
     */
    public function isSatisfiedBy(float $value, float $threshold): bool
    {
        return match ($this) {
            self::Above => $value > $threshold,
            self::Below => $value < $threshold,
        };
    }

    public function symbol(): string
    {
        return match ($this) {
            self::Above => '>',
            self::Below => '<',
        };
    }
}
```

`app/Enums/FeedEventLevel.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum FeedEventLevel: string
{
    case Info = 'info';
    case Warn = 'warn';
    case Error = 'error';
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/pest tests/Unit/EnumsTest.php`
Expected: PASS, 7 tests.

- [ ] **Step 5: Run the gates and commit**

```bash
vendor/bin/pint
vendor/bin/phpstan analyse --memory-limit=512M
vendor/bin/pest
git add -A
git commit -m "feat: add backed enums for every status field

AssetType, DriverState, TickSource, AlertMetric, AlertCondition and
FeedEventLevel. DriverState carries the per-driver stale threshold and
AlertCondition owns its comparison, so neither is reimplemented at a call
site with a subtly different boundary.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 5: Migrations

**Files:**
- Create: seven migrations under `database/migrations/`
- Test: `tests/Feature/SchemaTest.php`

**Interfaces:**
- Consumes: nothing (migrations reference enum *values* as strings, not the enum classes)
- Produces: tables `symbols`, `watchlists`, `watchlist_symbols`, `ticks`, `feed_events`, `alert_rules`, `alert_events`. Task 6's models map onto exactly these column names.

Generate each with `php artisan make:migration` so timestamps order correctly, then replace the body. Create them in this order — foreign keys depend on it.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/SchemaTest.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

it('creates every tapehouse table', function (string $table): void {
    expect(Schema::hasTable($table))->toBeTrue();
})->with([
    'symbols',
    'watchlists',
    'watchlist_symbols',
    'ticks',
    'feed_events',
    'alert_rules',
    'alert_events',
]);

it('stores money as numeric at full precision, never float or string', function (string $table, string $column, string $definition): void {
    expect(Schema::getColumnType($table, $column, true))->toBe($definition);
})->with([
    ['ticks', 'price', 'numeric(18,8)'],
    ['ticks', 'day_change', 'numeric(18,8)'],
    ['ticks', 'day_change_pct', 'numeric(9,4)'],
    ['alert_rules', 'threshold', 'numeric(18,8)'],
    ['alert_events', 'price', 'numeric(18,8)'],
]);

it('gives symbols a per-symbol display precision', function (): void {
    expect(Schema::hasColumn('symbols', 'price_decimals'))->toBeTrue();
});

it('stores feed event context as jsonb', function (): void {
    expect(Schema::getColumnType('feed_events', 'context'))->toBe('jsonb');
});

it('keeps ticks immutable — no updated_at', function (): void {
    expect(Schema::hasColumn('ticks', 'created_at'))->toBeFalse()
        ->and(Schema::hasColumn('ticks', 'updated_at'))->toBeFalse()
        ->and(Schema::hasColumn('ticks', 'quoted_at'))->toBeTrue()
        ->and(Schema::hasColumn('ticks', 'received_at'))->toBeTrue();
});

it('carries the alert metric column the designed panel needs', function (): void {
    expect(Schema::hasColumn('alert_rules', 'metric'))->toBeTrue();
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/pest tests/Feature/SchemaTest.php`
Expected: FAIL — every table missing.

- [ ] **Step 3: Create the symbols migration**

```bash
php artisan make:migration create_symbols_table
```

Body:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('symbols', function (Blueprint $table): void {
            $table->id();
            $table->string('ticker', 32)->unique();
            $table->string('name', 128);
            $table->string('asset_type', 16);
            $table->string('exchange', 32)->nullable();
            $table->string('currency', 8);
            $table->unsignedTinyInteger('price_decimals')->default(2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('symbols');
    }
};
```

- [ ] **Step 4: Create the watchlists and watchlist_symbols migrations**

```bash
php artisan make:migration create_watchlists_table
php artisan make:migration create_watchlist_symbols_table
```

`create_watchlists_table` body:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('watchlists', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('name', 64);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('watchlists');
    }
};
```

`create_watchlist_symbols_table` body:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('watchlist_symbols', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('watchlist_id')->constrained()->cascadeOnDelete();
            $table->foreignId('symbol_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['watchlist_id', 'symbol_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('watchlist_symbols');
    }
};
```

- [ ] **Step 5: Create the ticks migration**

```bash
php artisan make:migration create_ticks_table
```

Body:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticks', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('symbol_id')->constrained()->cascadeOnDelete();
            $table->decimal('price', 18, 8);
            $table->decimal('day_change', 18, 8)->nullable();
            $table->decimal('day_change_pct', 9, 4)->nullable();
            $table->string('source', 16);
            $table->timestampTz('quoted_at', 6);
            $table->timestampTz('received_at', 6);

            // No timestamps(): ticks are immutable, and an append-heavy table
            // should not carry two columns nothing ever reads.
            $table->index(['symbol_id', 'quoted_at']);
            $table->index('quoted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticks');
    }
};
```

A plain btree index serves `ORDER BY quoted_at DESC` — PostgreSQL scans indexes backwards at the same cost — so no explicit `DESC` index is needed.

- [ ] **Step 6: Create the feed_events migration**

```bash
php artisan make:migration create_feed_events_table
```

Body:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feed_events', function (Blueprint $table): void {
            $table->id();
            $table->string('level', 8);
            $table->string('type', 64);
            $table->text('message');
            $table->jsonb('context')->nullable();
            $table->timestampTz('occurred_at', 3);

            $table->index('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feed_events');
    }
};
```

- [ ] **Step 7: Create the alert_rules and alert_events migrations**

```bash
php artisan make:migration create_alert_rules_table
php artisan make:migration create_alert_events_table
```

`create_alert_rules_table` body:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('symbol_id')->constrained()->cascadeOnDelete();
            $table->string('metric', 16);
            $table->string('condition', 8);
            $table->decimal('threshold', 18, 8);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('cooldown_seconds')->default(60);
            $table->timestampTz('last_fired_at', 3)->nullable();
            $table->timestamps();

            $table->index(['is_active', 'symbol_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_rules');
    }
};
```

`create_alert_events_table` body:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('alert_rule_id')->constrained()->cascadeOnDelete();
            $table->decimal('price', 18, 8);
            $table->timestampTz('fired_at', 3);

            $table->index('fired_at');
            $table->index('alert_rule_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_events');
    }
};
```

- [ ] **Step 8: Migrate and run the test**

```bash
php artisan migrate
vendor/bin/pest tests/Feature/SchemaTest.php
```

Expected: PASS — 7 table cases, 5 money-column cases, and the four structural tests.

- [ ] **Step 9: Verify the migrations roll back cleanly**

```bash
php artisan migrate:fresh
```

Expected: all migrations drop and re-run without a foreign key error. This catches ordering mistakes that `migrate` alone hides.

- [ ] **Step 10: Run the gates and commit**

```bash
vendor/bin/pint
vendor/bin/phpstan analyse --memory-limit=512M
vendor/bin/pest
git add -A
git commit -m "feat: add the tapehouse schema

Seven tables. Money is numeric(18,8) throughout, feed event context is jsonb,
and ticks carry no timestamps because they are immutable and append-heavy.
alert_rules gains a metric column so the designed alerts panel, which mixes
price and change% rules, is buildable.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 6: Models and factories

**Files:**
- Create: `app/Models/{Symbol,Watchlist,WatchlistSymbol,Tick,FeedEvent,AlertRule,AlertEvent}.php`
- Create: `database/factories/{SymbolFactory,WatchlistFactory,TickFactory,FeedEventFactory,AlertRuleFactory,AlertEventFactory}.php`
- Modify: `app/Models/User.php`
- Test: `tests/Feature/ModelsTest.php`

**Interfaces:**
- Consumes: enums from Task 4, tables from Task 5
- Produces:
  - `Symbol` — `$ticker`, `$name`, `$assetType: AssetType`, `$priceDecimals: int`, `->ticks()`, `->watchlists()`
  - `Watchlist` — `->user()`, `->symbols(): BelongsToMany`
  - `Tick` — `$price: string`, `$source: TickSource`, `$quotedAt`, `$receivedAt`, `public $timestamps = false`
  - `FeedEvent` — `$level: FeedEventLevel`, `$context: array`, `public $timestamps = false`
  - `AlertRule` — `$metric: AlertMetric`, `$condition: AlertCondition`, `->symbol()`, `->events()`
  - `AlertEvent` — `->rule()`
  - `User` — `->watchlist(): HasOne`, `->alertRules(): HasMany`

  Plan 2's `TickBuffer` inserts into `ticks` directly rather than through `Tick`; Plan 3's controllers eager-load these relationships.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/ModelsTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\AlertCondition;
use App\Enums\AlertMetric;
use App\Enums\AssetType;
use App\Enums\FeedEventLevel;
use App\Enums\TickSource;
use App\Models\AlertEvent;
use App\Models\AlertRule;
use App\Models\FeedEvent;
use App\Models\Symbol;
use App\Models\Tick;
use App\Models\User;
use App\Models\Watchlist;
use Carbon\CarbonImmutable;

it('casts the symbol asset type to an enum', function (): void {
    $symbol = Symbol::factory()->create(['asset_type' => AssetType::Forex]);

    expect($symbol->refresh()->asset_type)->toBe(AssetType::Forex);
});

it('links a user to one watchlist of many symbols', function (): void {
    $user = User::factory()->create();
    $watchlist = Watchlist::factory()->for($user)->create();
    $symbols = Symbol::factory()->count(3)->create();

    $watchlist->symbols()->attach(
        $symbols->pluck('id')->mapWithKeys(fn (int $id, int $i): array => [$id => ['position' => $i]])->all()
    );

    expect($user->watchlist->is($watchlist))->toBeTrue()
        ->and($watchlist->symbols)->toHaveCount(3)
        ->and($watchlist->symbols->first()->pivot->position)->toBe(0);
});

it('casts tick source and keeps ticks timestamp-free', function (): void {
    $tick = Tick::factory()->create(['source' => TickSource::Polling]);

    expect($tick->refresh()->source)->toBe(TickSource::Polling)
        ->and((new Tick)->usesTimestamps())->toBeFalse();
});

it('casts feed event level and decodes jsonb context', function (): void {
    $event = FeedEvent::factory()->create([
        'level' => FeedEventLevel::Warn,
        'context' => ['from' => 'websocket', 'to' => 'polling'],
    ]);

    // Assert key by key, never `toBe()` on the whole array. PostgreSQL's jsonb
    // stores object keys sorted by length then bytewise, so it hands back
    // ['to' => ..., 'from' => ...] — and `toBe()` is `===`, which is
    // order-sensitive for arrays. The reordering is jsonb working correctly,
    // not a bug to design around: do not downgrade the column to `json` to
    // make a whole-array comparison pass.
    expect($event->refresh()->level)->toBe(FeedEventLevel::Warn)
        ->and($event->context)->toHaveCount(2)
        ->and($event->context['from'])->toBe('websocket')
        ->and($event->context['to'])->toBe('polling');
});

it('casts alert rule metric and condition', function (): void {
    $rule = AlertRule::factory()->create([
        'metric' => AlertMetric::ChangePct,
        'condition' => AlertCondition::Below,
    ]);

    expect($rule->refresh()->metric)->toBe(AlertMetric::ChangePct)
        ->and($rule->condition)->toBe(AlertCondition::Below);
});

it('links alert events back to their rule', function (): void {
    $event = AlertEvent::factory()->create();

    expect($event->rule)->toBeInstanceOf(AlertRule::class)
        ->and($event->rule->events->first()->is($event))->toBeTrue();
});

it('cascades deletes from symbol to tick', function (): void {
    $tick = Tick::factory()->create();

    $tick->symbol->delete();

    expect(Tick::find($tick->id))->toBeNull();
});

it('round-trips eight decimal places without narrowing through a float', function (): void {
    $tick = Tick::factory()->create([
        'price' => '12345.12345678',
        'day_change' => '-0.00000001',
    ]);

    $fresh = $tick->refresh();

    // Postgres numerics must arrive as strings. A float cast on any money
    // column would silently narrow every price the system ever reads.
    expect($fresh->price)->toBeString()->toBe('12345.12345678')
        ->and($fresh->day_change)->toBeString()->toBe('-0.00000001');
});

it('preserves sub-second precision through the eloquent write path', function (): void {
    $quotedAt = CarbonImmutable::parse('2026-08-10 12:00:00.123456');

    $tick = Tick::factory()->create([
        'quoted_at' => $quotedAt,
        'received_at' => $quotedAt->addMilliseconds(40),
    ]);

    $fresh = $tick->refresh();

    // The lag between these two is the number the ops panel reports. Laravel's
    // default date format has no fractional part, so without $dateFormat both
    // collapse to the same whole second and the lag reads as zero.
    expect($fresh->quoted_at->format('u'))->toBe('123456')
        ->and($fresh->received_at->format('u'))->toBe('163456')
        ->and($fresh->received_at->diffInMilliseconds($fresh->quoted_at))->toBe(-40.0);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/pest tests/Feature/ModelsTest.php`
Expected: FAIL with `Class "App\Models\Symbol" not found`.

- [ ] **Step 3: Write the models**

`app/Models/Symbol.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AssetType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Symbol extends Model
{
    /** @use HasFactory<\Database\Factories\SymbolFactory> */
    use HasFactory;

    protected $fillable = [
        'ticker',
        'name',
        'asset_type',
        'exchange',
        'currency',
        'price_decimals',
        'is_active',
    ];

    /** @return HasMany<Tick, $this> */
    public function ticks(): HasMany
    {
        return $this->hasMany(Tick::class);
    }

    /** @return BelongsToMany<Watchlist, $this> */
    public function watchlists(): BelongsToMany
    {
        return $this->belongsToMany(Watchlist::class, 'watchlist_symbols')
            ->using(WatchlistSymbol::class)
            ->withPivot('position')
            ->withTimestamps();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'asset_type' => AssetType::class,
            'price_decimals' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
```

`app/Models/Watchlist.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Watchlist extends Model
{
    /** @use HasFactory<\Database\Factories\WatchlistFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'name'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsToMany<Symbol, $this> */
    public function symbols(): BelongsToMany
    {
        return $this->belongsToMany(Symbol::class, 'watchlist_symbols')
            ->using(WatchlistSymbol::class)
            ->withPivot('position')
            ->withTimestamps()
            ->orderBy('watchlist_symbols.position');
    }
}
```

`app/Models/WatchlistSymbol.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class WatchlistSymbol extends Pivot
{
    protected $table = 'watchlist_symbols';

    public $incrementing = true;

    protected $fillable = ['watchlist_id', 'symbol_id', 'position'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['position' => 'integer'];
    }
}
```

`app/Models/Tick.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TickSource;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Tick extends Model
{
    /** @use HasFactory<\Database\Factories\TickFactory> */
    use HasFactory;

    /**
     * Ticks are immutable and append-heavy. Two timestamp columns nothing ever
     * reads would cost write throughput on the hottest table in the schema.
     */
    public $timestamps = false;

    /**
     * Laravel's base grammar formats dates as 'Y-m-d H:i:s' with no fractional
     * part, and PostgresGrammar does not override it — so without this every
     * write truncates to whole seconds regardless of the column's declared
     * precision. On ticks that would destroy received_at - quoted_at, which is
     * the ingest lag the ops panel reports.
     */
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = [
        'symbol_id',
        'price',
        'day_change',
        'day_change_pct',
        'source',
        'quoted_at',
        'received_at',
    ];

    /** @return BelongsTo<Symbol, $this> */
    public function symbol(): BelongsTo
    {
        return $this->belongsTo(Symbol::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'source' => TickSource::class,
            'quoted_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
        ];
    }
}
```

`price`, `day_change` and `day_change_pct` are deliberately **not** cast to float. Postgres returns them as strings and they stay strings until something formats them, so no precision is lost in transit.

`app/Models/FeedEvent.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FeedEventLevel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FeedEvent extends Model
{
    /** @use HasFactory<\Database\Factories\FeedEventFactory> */
    use HasFactory;

    public $timestamps = false;

    /**
     * Laravel's base grammar formats dates as 'Y-m-d H:i:s' with no fractional
     * part, and PostgresGrammar does not override it. Without it the
     * precision(3) columns on this table cannot hold a fraction.
     */
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = ['level', 'type', 'message', 'context', 'occurred_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'level' => FeedEventLevel::class,
            'context' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
```

`app/Models/AlertRule.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AlertCondition;
use App\Enums\AlertMetric;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AlertRule extends Model
{
    /** @use HasFactory<\Database\Factories\AlertRuleFactory> */
    use HasFactory;

    /**
     * Laravel's base grammar formats dates as 'Y-m-d H:i:s' with no fractional
     * part, and PostgresGrammar does not override it. Without it the
     * precision(3) columns on this table cannot hold a fraction.
     */
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = [
        'user_id',
        'symbol_id',
        'metric',
        'condition',
        'threshold',
        'is_active',
        'cooldown_seconds',
        'last_fired_at',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Symbol, $this> */
    public function symbol(): BelongsTo
    {
        return $this->belongsTo(Symbol::class);
    }

    /** @return HasMany<AlertEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(AlertEvent::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'metric' => AlertMetric::class,
            'condition' => AlertCondition::class,
            'is_active' => 'boolean',
            'cooldown_seconds' => 'integer',
            'last_fired_at' => 'immutable_datetime',
        ];
    }
}
```

`app/Models/AlertEvent.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AlertEvent extends Model
{
    /** @use HasFactory<\Database\Factories\AlertEventFactory> */
    use HasFactory;

    public $timestamps = false;

    /**
     * Laravel's base grammar formats dates as 'Y-m-d H:i:s' with no fractional
     * part, and PostgresGrammar does not override it. Without it the
     * precision(3) columns on this table cannot hold a fraction.
     */
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = ['alert_rule_id', 'price', 'fired_at'];

    /** @return BelongsTo<AlertRule, $this> */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(AlertRule::class, 'alert_rule_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['fired_at' => 'immutable_datetime'];
    }
}
```

- [ ] **Step 4: Add the relationships to User**

In `app/Models/User.php`, add `declare(strict_types=1);` below the opening tag if Pint has not already, then add these imports and methods:

```php
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
```

```php
    /** @return HasOne<Watchlist, $this> */
    public function watchlist(): HasOne
    {
        return $this->hasOne(Watchlist::class);
    }

    /** @return HasMany<AlertRule, $this> */
    public function alertRules(): HasMany
    {
        return $this->hasMany(AlertRule::class);
    }
```

- [ ] **Step 5: Write the factories**

`database/factories/SymbolFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AssetType;
use App\Models\Symbol;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Symbol> */
class SymbolFactory extends Factory
{
    protected $model = Symbol::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'ticker' => mb_strtoupper($this->faker->unique()->lexify('????')),
            'name' => $this->faker->company(),
            'asset_type' => AssetType::Stock,
            'exchange' => 'NASDAQ',
            'currency' => 'USD',
            'price_decimals' => 2,
            'is_active' => true,
        ];
    }
}
```

`database/factories/WatchlistFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Models\Watchlist;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Watchlist> */
class WatchlistFactory extends Factory
{
    protected $model = Watchlist::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => 'Default',
        ];
    }
}
```

`database/factories/TickFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TickSource;
use App\Models\Symbol;
use App\Models\Tick;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/** @extends Factory<Tick> */
class TickFactory extends Factory
{
    protected $model = Tick::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $quotedAt = Carbon::now()->subMilliseconds(40);

        return [
            'symbol_id' => Symbol::factory(),
            'price' => $this->faker->randomFloat(8, 1, 1000),
            'day_change' => $this->faker->randomFloat(8, -10, 10),
            'day_change_pct' => $this->faker->randomFloat(4, -5, 5),
            'source' => TickSource::WebSocket,
            'quoted_at' => $quotedAt,
            'received_at' => $quotedAt->copy()->addMilliseconds(40),
        ];
    }
}
```

`database/factories/FeedEventFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\FeedEventLevel;
use App\Models\FeedEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/** @extends Factory<FeedEvent> */
class FeedEventFactory extends Factory
{
    protected $model = FeedEvent::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'level' => FeedEventLevel::Info,
            'type' => 'driver.transition',
            'message' => 'ws demoted → polling. credit budget exhausted.',
            'context' => ['from' => 'websocket', 'to' => 'polling'],
            'occurred_at' => Carbon::now(),
        ];
    }
}
```

`database/factories/AlertRuleFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AlertCondition;
use App\Enums\AlertMetric;
use App\Models\AlertRule;
use App\Models\Symbol;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AlertRule> */
class AlertRuleFactory extends Factory
{
    protected $model = AlertRule::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'symbol_id' => Symbol::factory(),
            'metric' => AlertMetric::Price,
            'condition' => AlertCondition::Above,
            'threshold' => '230.00000000',
            'is_active' => true,
            'cooldown_seconds' => 60,
            'last_fired_at' => null,
        ];
    }
}
```

`database/factories/AlertEventFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AlertEvent;
use App\Models\AlertRule;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/** @extends Factory<AlertEvent> */
class AlertEventFactory extends Factory
{
    protected $model = AlertEvent::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'alert_rule_id' => AlertRule::factory(),
            'price' => '230.06000000',
            'fired_at' => Carbon::now(),
        ];
    }
}
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `vendor/bin/pest tests/Feature/ModelsTest.php`
Expected: PASS, 9 tests.

- [ ] **Step 7: Run the gates and commit**

```bash
vendor/bin/pint
vendor/bin/phpstan analyse --memory-limit=512M
vendor/bin/pest
git add -A
git commit -m "feat: add models, casts and factories

Every status column casts to its backed enum. Ticks and alert events disable
timestamps, and money columns stay uncast so Postgres numerics arrive as
strings rather than being narrowed through float.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 7: Seeders

**Files:**
- Create: `database/seeders/{OperatorSeeder,SymbolSeeder,WatchlistSeeder}.php`
- Modify: `database/seeders/DatabaseSeeder.php`
- Test: `tests/Feature/SeederTest.php`

**Interfaces:**
- Consumes: models from Task 6
- Produces: `php artisan db:seed` yields one operator (`operator@tapehouse.dev` / `tapehouse`), 40 symbols, and a 10-symbol watchlist. Plan 2's `TapeIngest` resolves its ticker list from these watchlist rows; Plan 4's login page uses these credentials.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/SeederTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\AssetType;
use App\Models\Symbol;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

beforeEach(function (): void {
    $this->seed();
});

it('seeds exactly one operator who can authenticate', function (): void {
    $user = User::sole();

    expect($user->email)->toBe('operator@tapehouse.dev')
        ->and(Hash::check('tapehouse', $user->password))->toBeTrue();
});

it('seeds a symbol universe spanning all three asset types', function (): void {
    expect(Symbol::count())->toBe(40)
        ->and(Symbol::where('asset_type', AssetType::Stock)->count())->toBeGreaterThan(0)
        ->and(Symbol::where('asset_type', AssetType::Forex)->count())->toBeGreaterThan(0)
        ->and(Symbol::where('asset_type', AssetType::Crypto)->count())->toBeGreaterThan(0);
});

it('gives XAU/USD two decimals despite being a forex pair', function (): void {
    $gold = Symbol::where('ticker', 'XAU/USD')->sole();

    expect($gold->asset_type)->toBe(AssetType::Forex)
        ->and($gold->price_decimals)->toBe(2);
});

it('seeds the ten symbols the console design renders, in order', function (): void {
    $watchlist = User::sole()->watchlist;

    expect($watchlist->symbols->pluck('ticker')->all())->toBe([
        'AAPL', 'MSFT', 'NVDA', 'SPY', 'EUR/USD',
        'GBP/USD', 'USD/JPY', 'BTC/USD', 'ETH/USD', 'XAU/USD',
    ]);
});

it('is idempotent', function (): void {
    $this->seed();

    expect(User::count())->toBe(1)
        ->and(Symbol::count())->toBe(40);
});
```

The idempotency test matters because `db:seed` gets run repeatedly during development and a seeder that duplicates rows breaks the unique constraint on `ticker` in a confusing way.

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/pest tests/Feature/SeederTest.php`
Expected: FAIL — no operator, zero symbols.

- [ ] **Step 3: Write the operator seeder**

`database/seeders/OperatorSeeder.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class OperatorSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'operator@tapehouse.dev'],
            [
                'name' => 'Operator',
                'password' => Hash::make('tapehouse'),
                'email_verified_at' => now(),
            ],
        );
    }
}
```

- [ ] **Step 4: Write the symbol seeder**

`database/seeders/SymbolSeeder.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AssetType;
use App\Models\Symbol;
use Illuminate\Database\Seeder;

class SymbolSeeder extends Seeder
{
    /**
     * Tickers use Twelve Data's exact format — slash-separated pairs for forex
     * and crypto, bare tickers for equities. price_decimals is per symbol
     * because precision does not follow asset type: XAU/USD quotes to 2 places
     * while most forex pairs quote to 5.
     *
     * @var list<array{0: string, 1: string, 2: AssetType, 3: ?string, 4: string, 5: int}>
     */
    private const SYMBOLS = [
        ['AAPL', 'Apple Inc', AssetType::Stock, 'NASDAQ', 'USD', 2],
        ['MSFT', 'Microsoft Corp', AssetType::Stock, 'NASDAQ', 'USD', 2],
        ['NVDA', 'NVIDIA Corp', AssetType::Stock, 'NASDAQ', 'USD', 2],
        ['TSLA', 'Tesla Inc', AssetType::Stock, 'NASDAQ', 'USD', 2],
        ['AMZN', 'Amazon.com Inc', AssetType::Stock, 'NASDAQ', 'USD', 2],
        ['GOOGL', 'Alphabet Inc Class A', AssetType::Stock, 'NASDAQ', 'USD', 2],
        ['META', 'Meta Platforms Inc', AssetType::Stock, 'NASDAQ', 'USD', 2],
        ['NFLX', 'Netflix Inc', AssetType::Stock, 'NASDAQ', 'USD', 2],
        ['AMD', 'Advanced Micro Devices Inc', AssetType::Stock, 'NASDAQ', 'USD', 2],
        ['INTC', 'Intel Corp', AssetType::Stock, 'NASDAQ', 'USD', 2],
        ['JPM', 'JPMorgan Chase & Co', AssetType::Stock, 'NYSE', 'USD', 2],
        ['V', 'Visa Inc', AssetType::Stock, 'NYSE', 'USD', 2],
        ['XOM', 'Exxon Mobil Corp', AssetType::Stock, 'NYSE', 'USD', 2],
        ['JNJ', 'Johnson & Johnson', AssetType::Stock, 'NYSE', 'USD', 2],
        ['WMT', 'Walmart Inc', AssetType::Stock, 'NYSE', 'USD', 2],
        ['PG', 'Procter & Gamble Co', AssetType::Stock, 'NYSE', 'USD', 2],
        ['DIS', 'Walt Disney Co', AssetType::Stock, 'NYSE', 'USD', 2],
        ['BA', 'Boeing Co', AssetType::Stock, 'NYSE', 'USD', 2],
        ['SPY', 'SPDR S&P 500 ETF Trust', AssetType::Stock, 'NYSE', 'USD', 2],
        ['QQQ', 'Invesco QQQ Trust', AssetType::Stock, 'NASDAQ', 'USD', 2],

        ['EUR/USD', 'Euro / US Dollar', AssetType::Forex, null, 'USD', 5],
        ['GBP/USD', 'Pound Sterling / US Dollar', AssetType::Forex, null, 'USD', 5],
        ['USD/JPY', 'US Dollar / Japanese Yen', AssetType::Forex, null, 'JPY', 5],
        ['USD/CHF', 'US Dollar / Swiss Franc', AssetType::Forex, null, 'CHF', 5],
        ['AUD/USD', 'Australian Dollar / US Dollar', AssetType::Forex, null, 'USD', 5],
        ['USD/CAD', 'US Dollar / Canadian Dollar', AssetType::Forex, null, 'CAD', 5],
        ['NZD/USD', 'New Zealand Dollar / US Dollar', AssetType::Forex, null, 'USD', 5],
        ['EUR/GBP', 'Euro / Pound Sterling', AssetType::Forex, null, 'GBP', 5],
        ['EUR/JPY', 'Euro / Japanese Yen', AssetType::Forex, null, 'JPY', 5],
        ['GBP/JPY', 'Pound Sterling / Japanese Yen', AssetType::Forex, null, 'JPY', 5],
        ['XAU/USD', 'Gold / US Dollar', AssetType::Forex, null, 'USD', 2],
        ['XAG/USD', 'Silver / US Dollar', AssetType::Forex, null, 'USD', 3],

        ['BTC/USD', 'Bitcoin / US Dollar', AssetType::Crypto, null, 'USD', 2],
        ['ETH/USD', 'Ether / US Dollar', AssetType::Crypto, null, 'USD', 2],
        ['SOL/USD', 'Solana / US Dollar', AssetType::Crypto, null, 'USD', 2],
        ['XRP/USD', 'XRP / US Dollar', AssetType::Crypto, null, 'USD', 4],
        ['ADA/USD', 'Cardano / US Dollar', AssetType::Crypto, null, 'USD', 4],
        ['DOGE/USD', 'Dogecoin / US Dollar', AssetType::Crypto, null, 'USD', 5],
        ['LTC/USD', 'Litecoin / US Dollar', AssetType::Crypto, null, 'USD', 2],
        ['BNB/USD', 'BNB / US Dollar', AssetType::Crypto, null, 'USD', 2],
    ];

    public function run(): void
    {
        foreach (self::SYMBOLS as [$ticker, $name, $assetType, $exchange, $currency, $decimals]) {
            Symbol::updateOrCreate(
                ['ticker' => $ticker],
                [
                    'name' => $name,
                    'asset_type' => $assetType,
                    'exchange' => $exchange,
                    'currency' => $currency,
                    'price_decimals' => $decimals,
                    'is_active' => true,
                ],
            );
        }
    }
}
```

- [ ] **Step 5: Write the watchlist seeder**

`database/seeders/WatchlistSeeder.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Symbol;
use App\Models\User;
use App\Models\Watchlist;
use Illuminate\Database\Seeder;

class WatchlistSeeder extends Seeder
{
    /**
     * The ten symbols the console design renders, in the order it renders
     * them. Mixed deliberately: 2-decimal equities, 5-decimal forex, and
     * crypto with thousands separators, so the tape's decimal alignment is
     * exercised the moment the app boots.
     *
     * @var list<string>
     */
    private const TICKERS = [
        'AAPL', 'MSFT', 'NVDA', 'SPY', 'EUR/USD',
        'GBP/USD', 'USD/JPY', 'BTC/USD', 'ETH/USD', 'XAU/USD',
    ];

    public function run(): void
    {
        $user = User::where('email', 'operator@tapehouse.dev')->sole();

        $watchlist = Watchlist::updateOrCreate(
            ['user_id' => $user->id],
            ['name' => 'Default'],
        );

        $symbols = Symbol::whereIn('ticker', self::TICKERS)
            ->get()
            ->keyBy('ticker');

        $attach = [];

        foreach (self::TICKERS as $position => $ticker) {
            $attach[$symbols[$ticker]->id] = ['position' => $position];
        }

        $watchlist->symbols()->sync($attach);
    }
}
```

`sync()` rather than `attach()` is what makes reseeding idempotent — `attach()` would violate the unique constraint on the second run.

- [ ] **Step 6: Wire the seeders together**

Replace `database/seeders/DatabaseSeeder.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            OperatorSeeder::class,
            SymbolSeeder::class,
            WatchlistSeeder::class,
        ]);
    }
}
```

Order matters: `WatchlistSeeder` reads both the operator and the symbols.

- [ ] **Step 7: Run the test to verify it passes**

Run: `vendor/bin/pest tests/Feature/SeederTest.php`
Expected: PASS, 5 tests.

- [ ] **Step 8: Seed the development database and verify by hand**

```bash
php artisan migrate:fresh --seed
php artisan tinker --execute="
\$w = App\Models\User::sole()->watchlist;
foreach (\$w->symbols as \$s) {
    printf(\"%-9s %-30s %s %d dp\n\", \$s->ticker, \$s->name, \$s->asset_type->value, \$s->price_decimals);
}"
```

Expected: ten rows in design order, `XAU/USD` showing `forex 2 dp`.

- [ ] **Step 9: Run the full suite and all gates**

```bash
vendor/bin/pint
vendor/bin/phpstan analyse --memory-limit=512M
vendor/bin/pest
```

Expected: PHPStan `[OK]`, Pest all green across every test file written in this plan.

- [ ] **Step 10: Commit and merge the feature branch**

```bash
git add -A
git commit -m "feat: seed the operator, symbol universe and console watchlist

Forty symbols across equities, forex and crypto in Twelve Data's exact ticker
format, plus the ten-symbol watchlist the console design renders. All three
seeders are idempotent so db:seed can be re-run during development.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"

git checkout develop
git merge --no-ff feature/foundation -m "Merge feature/foundation into develop

Laravel 13 on PostgreSQL and Redis with the full Tapehouse schema, enums,
models and seed data. Pest, Pint and Larastan level 6 all green.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Definition of done

- [ ] `php artisan about` reports `pgsql`, `redis` cache and `redis` queue
- [ ] `php artisan migrate:fresh --seed` runs clean from an empty database
- [ ] `vendor/bin/pest` — all green
- [ ] `vendor/bin/phpstan analyse` — `[OK] No errors` at level 6
- [ ] `vendor/bin/pint --test` — clean
- [ ] No `vite.config.js`, no Tailwind, no `database.sqlite` anywhere in the tree
- [ ] `feature/foundation` merged into `develop` — deferred until after the whole-branch review; handled by the controller, not by Task 7

## What this plan does not build

`CreditBudget`, the drivers, `DriverManager`, `TickBuffer`, `QuoteCache`, `FeedMetrics`, `QuoteBroadcaster`, `TapeIngest` — all Plan 2. Reverb, events, channels and the REST API — Plan 3. Webpack, SCSS, Blade and the JavaScript modules — Plan 4. Production Dockerfile, `docker-compose.yml`, supervisord, nginx, CI, `CLAUDE.md`, `README.md` — Plan 5.
