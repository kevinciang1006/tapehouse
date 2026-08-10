# Tapehouse Plan 2 — Ingest Subsystem Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A long-running `tape:ingest` command that pulls live quotes from Twelve Data through three interchangeable drivers, budgets API credits with a Redis token bucket, caches last prices in Redis, batch-writes an audit trail to PostgreSQL, and demotes itself from WebSocket to polling when the upstream refuses.

**Architecture:** One ReactPHP event loop. `DriverManager` owns the current `UpstreamDriver` and supervises demotion and promotion; a 250ms timer calls `tick()` on the current driver. Quotes fan out to `QuoteCache` (Redis, the read path), `TickBuffer` (batched Postgres, the audit path) and `FeedMetrics` (Redis counters). Nothing on the quote path blocks.

**Tech Stack:** PHP 8.4, Laravel 13.8, ReactPHP event-loop 1.x, ratchet/pawl, Guzzle, Redis via predis, PostgreSQL 16, Pest 5.

## Global Constraints

Inherited from `docs/superpowers/specs/2026-08-10-tapehouse-design.md` §8 and Plan 1. Every task below is bound by these.

- `declare(strict_types=1)` at the top of **every** PHP file, including tests.
- **No facades inside `app/Services/**`.** Every dependency arrives through the constructor. No `Redis::`, `DB::`, `Log::`, `config()` or `app()` inside a service — the services must be unit-testable without the container. Configuration values are passed in as constructor scalars, read from `config/tapehouse.php` at the binding site in a service provider.
- Backed PHP enums for every status value. No string literals for `websocket`, `polling`, `simulated`, `above`, `below`.
- All money stays a **string** end to end. Never cast a price to float for storage or comparison. `Quote` holds `string $price`.
- Constructor property promotion; `readonly` where immutable. Full parameter and return types including closures. No `array` without a docblock shape.
- Pint (Laravel preset + `declare_strict_types`) and Larastan **level 6** must pass before every commit. No baseline, no `ignoreErrors`, no level reduction. `parseModelCastsMethod: true` is already on — models are type-checked against their `casts()`.
- Git Flow: branch `feature/ingest` from `develop`. Commit at the end of each task. **Do not merge** — the controller merges after a whole-branch review.
- **Git commands must run with the sandbox disabled** in this environment. `php` is keg-only: `export PATH="/opt/homebrew/opt/php@8.4/bin:$PATH"`.

## Environment facts

- `TWELVEDATA_API_KEY` in `.env` is **empty**. Every task except the final live-verification step must pass without it. Tests must never contact the real API — `phpunit.xml` pins `TWELVEDATA_API_KEY=test-key`.
- Tests use **real Redis** on `REDIS_DB=15` (already pinned in `phpunit.xml`). The Lua token bucket cannot be mocked; spec §7 requires a real server.
- Twelve Data bills **one credit per symbol**, not per request. An 8-symbol batch costs 8 credits.
- Redis keys, from the spec: `tape:quote:{ticker}`, `tape:budget:tokens`, `tape:budget:refilled_at`, `tape:metrics:lag`, `tape:metrics:ticks_minute:{min}`, `tape:driver:state`, `tape:poll:cursor`, `tape:control:state`.

## Deviations from the spec introduced by this plan

| # | Change | Why |
|---|---|---|
| D13 | `QuoteBroadcaster`, the events and Reverb move to Plan 3 | Broadcasting needs Reverb configured and channels authorised, which is Plan 3's subject. Plan 2 ends with a loop that provably ingests; Plan 3 adds the outbound half. Each plan stays independently verifiable. |
| D14 | `CreditBudget::tryConsume()` returns `int` granted, not `bool` | Already recorded in the spec §3.3. Restated because it is the single most load-bearing signature in this plan. |
| D15 | The Lua script returns `{granted, remaining}` | `available()` would otherwise need a second round trip and could observe a different state than the consume that just ran. |
| D16 | Services take a `CarbonImmutable` clock via `CarbonImmutable::now()`, with tests using `CarbonImmutable::setTestNow()` | Carbon is not a Laravel facade and is designed for exactly this. Inventing a `ClockInterface` wrapper to satisfy the no-facades rule would be ceremony; the rule exists to keep services container-free, and Carbon already is. |

## File Structure

| Path | Responsibility |
|---|---|
| `app/Services/Upstream/DTO/Quote.php` | Immutable quote value object; owns `lagMs()` |
| `app/Services/Upstream/UpstreamDriver.php` | The driver interface |
| `app/Services/Upstream/TwelveDataClient.php` | Thin HTTP wrapper over the REST API |
| `app/Services/Upstream/PollingDriver.php` | Credit-budgeted REST polling with a rotating cursor |
| `app/Services/Upstream/WebSocketDriver.php` | pawl streaming driver |
| `app/Services/Upstream/SimulatedDriver.php` | Random-walk driver, config-gated |
| `app/Services/Upstream/DriverManager.php` | Lifecycle, demotion, promotion, control flag |
| `app/Services/Budget/CreditBudget.php` | Redis Lua token bucket |
| `app/Services/Quotes/QuoteCache.php` | Redis last-price hash — the read path |
| `app/Services/Quotes/TickBuffer.php` | Batched Postgres writer — the audit path |
| `app/Services/Metrics/FeedMetrics.php` | Lag window, tick counters, ops snapshot |
| `app/Services/Control/FeedControl.php` | Redis stop/start flag |
| `app/Providers/TapehouseServiceProvider.php` | Binds every service with its config scalars |
| `app/Console/Commands/TapeIngest.php` | The long-running loop |
| `app/Console/Commands/TapePrune.php` | Deletes ticks past the retention window |

---

### Task 1: `CreditBudget` — the Redis token bucket

**Files:**
- Create: `app/Services/Budget/CreditBudget.php`
- Create: `app/Providers/TapehouseServiceProvider.php`
- Modify: `bootstrap/providers.php`
- Test: `tests/Feature/Budget/CreditBudgetTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks
- Produces:
  - `CreditBudget::__construct(Connection $redis, int $capacity, int $refillPerMinute)`
  - `tryConsume(int $tokens = 1): int` — returns tokens **actually granted**, 0..$tokens
  - `available(): int`
  - `capacity(): int`
  - `secondsUntilNextToken(): int`
  Task 3's `PollingDriver` calls `tryConsume(count($slice))` and honours partial grants. Task 7's ops snapshot calls `available()` and `capacity()`.

This is built first and tested hardest because everything downstream depends on it being correct.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Budget/CreditBudgetTest.php`:

```php
<?php

declare(strict_types=1);

use App\Services\Budget\CreditBudget;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Redis;

beforeEach(function (): void {
    Redis::connection()->flushdb();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-10 12:00:00'));
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function budget(int $capacity = 8, int $refill = 8): CreditBudget
{
    return new CreditBudget(Redis::connection(), $capacity, $refill);
}

it('starts full', function (): void {
    expect(budget()->available())->toBe(8);
});

it('consumes down to zero', function (): void {
    $b = budget();

    expect($b->tryConsume(5))->toBe(5)
        ->and($b->available())->toBe(3)
        ->and($b->tryConsume(3))->toBe(3)
        ->and($b->available())->toBe(0);
});

it('refuses when empty', function (): void {
    $b = budget();
    $b->tryConsume(8);

    expect($b->tryConsume(1))->toBe(0)
        ->and($b->available())->toBe(0);
});

it('grants partially rather than refusing outright', function (): void {
    $b = budget();
    $b->tryConsume(5);

    // Twelve Data bills per symbol, so an 8-symbol slice against 3 remaining
    // tokens must poll 3 symbols — not zero, and not eight.
    expect($b->tryConsume(8))->toBe(3)
        ->and($b->available())->toBe(0);
});

it('never grants more than requested even when full', function (): void {
    expect(budget()->tryConsume(3))->toBe(3);
});

it('refills at the configured rate over time', function (): void {
    $b = budget();
    $b->tryConsume(8);
    expect($b->available())->toBe(0);

    // 8 per minute = 1 token per 7.5s. At 30s, 4 tokens.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-10 12:00:30'));
    expect($b->available())->toBe(4);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-10 12:01:00'));
    expect($b->available())->toBe(8);
});

it('caps refill at capacity', function (): void {
    $b = budget();

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-10 13:00:00'));

    expect($b->available())->toBe(8);
});

it('accumulates fractional refill progress instead of discarding it', function (): void {
    $b = budget();
    $b->tryConsume(8);

    // Poll every 3s for 9s. Each call alone earns zero whole tokens at
    // 1 per 7.5s — if the refill timestamp were advanced to `now` on every
    // call, the bucket would never refill at all under a fast poll loop.
    foreach ([3, 6, 9] as $offset) {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-10 12:00:00')->addSeconds($offset));
        $b->available();
    }

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-10 12:00:15'));

    expect($b->available())->toBe(2);
});

it('is atomic across concurrent consumers', function (): void {
    $b = budget();

    // Twenty single-token attempts against a capacity of 8 must grant
    // exactly 8 in total, no matter the interleaving.
    $granted = 0;
    for ($i = 0; $i < 20; $i++) {
        $granted += $b->tryConsume(1);
    }

    expect($granted)->toBe(8)
        ->and($b->available())->toBe(0);
});

it('reports seconds until the next whole token', function (): void {
    $b = budget();
    $b->tryConsume(8);

    expect($b->secondsUntilNextToken())->toBe(8);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-10 12:00:05'));

    expect($b->secondsUntilNextToken())->toBe(3);
});

it('reports zero seconds when tokens are already available', function (): void {
    expect(budget()->secondsUntilNextToken())->toBe(0);
});

it('treats a zero request as a refill-only probe', function (): void {
    $b = budget();

    expect($b->tryConsume(0))->toBe(0)
        ->and($b->available())->toBe(8);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/pest tests/Feature/Budget/CreditBudgetTest.php`
Expected: FAIL with `Class "App\Services\Budget\CreditBudget" not found`.

- [ ] **Step 3: Write `CreditBudget`**

Create `app/Services/Budget/CreditBudget.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Budget;

use Carbon\CarbonImmutable;
use Illuminate\Redis\Connections\Connection;

/**
 * Redis token bucket guarding the Twelve Data credit allowance.
 *
 * Twelve Data bills one credit per symbol, not per request, so callers ask for
 * as many tokens as they have symbols and act on however many they get. A
 * boolean would force the caller to either overspend or stall its whole slice.
 */
final readonly class CreditBudget
{
    private const TOKENS_KEY = 'tape:budget:tokens';

    private const REFILLED_AT_KEY = 'tape:budget:refilled_at';

    /**
     * Read-modify-write in one round trip so concurrent workers cannot both
     * observe the same tokens and both spend them. Returns {granted, remaining}
     * because a caller that had to re-read the count could see a different
     * state than the consume it just performed.
     */
    private const SCRIPT = <<<'LUA'
    local capacity = tonumber(ARGV[1])
    local per_second = tonumber(ARGV[2]) / 60.0
    local now = tonumber(ARGV[3])
    local requested = tonumber(ARGV[4])

    local tokens = tonumber(redis.call('GET', KEYS[1]))
    local refilled_at = tonumber(redis.call('GET', KEYS[2]))

    if tokens == nil or refilled_at == nil then
        tokens = capacity
        refilled_at = now
    end

    local elapsed = now - refilled_at
    if elapsed > 0 then
        local gained = math.floor(elapsed * per_second)
        if gained > 0 then
            tokens = math.min(capacity, tokens + gained)
            -- Advance by exactly what was earned, never to `now`. Advancing to
            -- `now` would discard the remainder on every call, so a loop
            -- polling faster than the refill interval would never refill.
            refilled_at = refilled_at + (gained / per_second)
            if refilled_at > now then
                refilled_at = now
            end
        end
    end

    local granted = math.min(tokens, requested)
    if granted < 0 then
        granted = 0
    end
    tokens = tokens - granted

    redis.call('SET', KEYS[1], tokens)
    redis.call('SET', KEYS[2], refilled_at)

    return {granted, tokens}
    LUA;

    public function __construct(
        private Connection $redis,
        private int $capacity,
        private int $refillPerMinute,
    ) {}

    /**
     * @return int tokens actually granted, between 0 and $tokens inclusive
     */
    public function tryConsume(int $tokens = 1): int
    {
        return $this->run(max(0, $tokens))[0];
    }

    public function available(): int
    {
        return $this->run(0)[1];
    }

    public function capacity(): int
    {
        return $this->capacity;
    }

    /**
     * Seconds until at least one token is available; 0 if one already is.
     */
    public function secondsUntilNextToken(): int
    {
        if ($this->available() > 0) {
            return 0;
        }

        $refilledAt = (float) ($this->redis->get(self::REFILLED_AT_KEY) ?? 0.0);
        $perSecond = $this->refillPerMinute / 60.0;

        $due = $refilledAt + (1.0 / $perSecond);

        return max(0, (int) ceil($due - $this->now()));
    }

    /**
     * @return array{0: int, 1: int} granted, remaining
     */
    private function run(int $requested): array
    {
        // `command('eval', ...)` rather than `->eval(...)`: Connection is
        // declared `@mixin \Redis`, so static analysis resolves a direct
        // ->eval() against phpredis's native signature instead of Laravel's
        // flattened one. `command()` is a real method on Connection and is
        // runtime-identical here — PredisConnection defines no eval() override.
        /** @var array{0: int, 1: int} $result */
        $result = $this->redis->command('eval', [
            self::SCRIPT,
            2,
            self::TOKENS_KEY,
            self::REFILLED_AT_KEY,
            (string) $this->capacity,
            (string) $this->refillPerMinute,
            (string) $this->now(),
            (string) $requested,
        ]);

        return [(int) $result[0], (int) $result[1]];
    }

    private function now(): float
    {
        return (float) CarbonImmutable::now()->format('U.u');
    }
}
```

- [ ] **Step 4: Write the service provider**

Create `app/Providers/TapehouseServiceProvider.php`. This is the ONLY place Tapehouse services meet the container — the services themselves take plain scalars so they unit-test without it:

```php
<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Budget\CreditBudget;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\ServiceProvider;

class TapehouseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CreditBudget::class, function ($app): CreditBudget {
            /** @var Config $config */
            $config = $app->make('config');

            /** @var RedisManager $redis */
            $redis = $app->make('redis');

            /** @var Connection $connection */
            $connection = $redis->connection();

            return new CreditBudget(
                $connection,
                (int) $config->get('tapehouse.budget.capacity'),
                (int) $config->get('tapehouse.budget.refill_per_minute'),
            );
        });
    }
}
```

Register it in `bootstrap/providers.php`:

```php
<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Providers\TapehouseServiceProvider;

return [
    AppServiceProvider::class,
    TapehouseServiceProvider::class,
];
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `vendor/bin/pest tests/Feature/Budget/CreditBudgetTest.php`
Expected: PASS, 12 tests.

If the fractional-accumulation test fails, the Lua is advancing `refilled_at` to `now` instead of by the amount earned. That is the bug the test exists to catch — fix the script, not the test.

- [ ] **Step 6: Confirm the test uses a real Redis and an isolated database**

```bash
redis-cli -n 15 dbsize
vendor/bin/pest tests/Feature/Budget/CreditBudgetTest.php
redis-cli -n 0 keys 'tape:budget:*'
```

Expected: the last command returns nothing — the test must not have touched the development database.

- [ ] **Step 7: Run the gates and commit**

```bash
vendor/bin/pint
vendor/bin/phpstan analyse --memory-limit=512M
vendor/bin/pest
git add -A
git commit -m "feat: add the redis token bucket credit budget

Read-modify-write in a single Lua script so concurrent workers cannot both
spend the same token. tryConsume returns the granted count rather than a
boolean, because Twelve Data bills per symbol and a partial grant is the
useful answer. Refill advances the timestamp by exactly what was earned so a
fast poll loop accumulates instead of discarding the remainder.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 2: `Quote` DTO and `TwelveDataClient`

**Files:**
- Create: `app/Services/Upstream/DTO/Quote.php`
- Create: `app/Services/Upstream/TwelveDataClient.php`
- Create: `app/Services/Upstream/UpstreamDriver.php`
- Test: `tests/Unit/Upstream/QuoteTest.php`, `tests/Feature/Upstream/TwelveDataClientTest.php`

**Interfaces:**
- Consumes: `TickSource` enum from Plan 1
- Produces:
  - `Quote::__construct(string $ticker, string $price, ?string $dayChange, ?string $dayChangePct, TickSource $source, CarbonImmutable $quotedAt, CarbonImmutable $receivedAt)` — all readonly promoted
  - `Quote::lagMs(): int`
  - `Quote::toTickRow(int $symbolId): array` — the row shape `TickBuffer` inserts
  - `TwelveDataClient::__construct(ClientInterface $http, string $apiKey, string $restUrl)`
  - `TwelveDataClient::quotes(array $tickers): array<Quote>` — throws `UpstreamAuthException` on a rejected key
  - `interface UpstreamDriver` with `name(): DriverState`, `start(array $tickers, callable $onQuote): void`, `tick(): void`, `stop(): void`, `isHealthy(): bool`, `lastError(): ?string`

Prices are **strings** throughout. Task 6's `TickBuffer` inserts them unchanged.

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/Upstream/QuoteTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\TickSource;
use App\Services\Upstream\DTO\Quote;
use Carbon\CarbonImmutable;

function quote(string $price = '228.41', int $lagMs = 40): Quote
{
    $quotedAt = CarbonImmutable::parse('2026-08-10 12:00:00.000000');

    return new Quote(
        ticker: 'AAPL',
        price: $price,
        dayChange: '1.82',
        dayChangePct: '0.80',
        source: TickSource::WebSocket,
        quotedAt: $quotedAt,
        receivedAt: $quotedAt->addMilliseconds($lagMs),
    );
}

it('keeps the price as a string so precision survives', function (): void {
    $q = quote('12345.12345678');

    expect($q->price)->toBeString()->toBe('12345.12345678');
});

it('computes lag in milliseconds', function (): void {
    expect(quote(lagMs: 40)->lagMs())->toBe(40)
        ->and(quote(lagMs: 0)->lagMs())->toBe(0);
});

it('never reports negative lag when upstream clocks run ahead', function (): void {
    // Twelve Data timestamps come from their clock, not ours. A quoted_at in
    // our future must read as 0 lag, not as a negative that would poison the
    // p50/p95 window.
    expect(quote(lagMs: -250)->lagMs())->toBe(0);
});

it('builds the tick row shape the buffer inserts', function (): void {
    $row = quote()->toTickRow(symbolId: 7);

    expect($row['symbol_id'])->toBe(7)
        ->and($row['price'])->toBe('228.41')
        ->and($row['day_change'])->toBe('1.82')
        ->and($row['source'])->toBe('websocket')
        ->and($row['quoted_at'])->toBe('2026-08-10 12:00:00.000000')
        ->and($row['received_at'])->toBe('2026-08-10 12:00:00.040000');
});
```

The `toTickRow` timestamps are pre-formatted with microseconds deliberately. Laravel's query grammar formats `DateTimeInterface` bindings as `Y-m-d H:i:s` with no fraction, so passing Carbon objects to a raw insert silently truncates — the same defect Plan 1 fixed on the Eloquent path.

Create `tests/Feature/Upstream/TwelveDataClientTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\TickSource;
use App\Services\Upstream\Exceptions\UpstreamAuthException;
use App\Services\Upstream\TwelveDataClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

function client(array $responses): TwelveDataClient
{
    $stack = HandlerStack::create(new MockHandler($responses));

    return new TwelveDataClient(
        new Client(['handler' => $stack]),
        'test-key',
        'https://api.twelvedata.com',
    );
}

it('parses a single-symbol response', function (): void {
    $c = client([new Response(200, [], json_encode([
        'symbol' => 'AAPL',
        'close' => '228.41',
        'change' => '1.82',
        'percent_change' => '0.80',
        'timestamp' => 1786089600,
    ]))]);

    $quotes = $c->quotes(['AAPL']);

    expect($quotes)->toHaveCount(1)
        ->and($quotes[0]->ticker)->toBe('AAPL')
        ->and($quotes[0]->price)->toBe('228.41')
        ->and($quotes[0]->source)->toBe(TickSource::Polling);
});

it('parses a batch response keyed by ticker', function (): void {
    $c = client([new Response(200, [], json_encode([
        'AAPL' => ['symbol' => 'AAPL', 'close' => '228.41', 'change' => '1.82', 'percent_change' => '0.80', 'timestamp' => 1786089600],
        'EUR/USD' => ['symbol' => 'EUR/USD', 'close' => '1.08234', 'change' => '-0.00041', 'percent_change' => '-0.04', 'timestamp' => 1786089600],
    ]))]);

    $quotes = $c->quotes(['AAPL', 'EUR/USD']);

    expect($quotes)->toHaveCount(2)
        ->and($quotes[1]->ticker)->toBe('EUR/USD')
        ->and($quotes[1]->price)->toBe('1.08234');
});

it('throws UpstreamAuthException when the key is rejected', function (): void {
    // The expected outcome on a trial key, not an exceptional one.
    $c = client([new Response(200, [], json_encode([
        'code' => 401,
        'message' => '**api_key** not valid',
        'status' => 'error',
    ]))]);

    $c->quotes(['AAPL']);
})->throws(UpstreamAuthException::class);

it('skips symbols the upstream reports an error for, keeping the rest', function (): void {
    $c = client([new Response(200, [], json_encode([
        'AAPL' => ['symbol' => 'AAPL', 'close' => '228.41', 'change' => '1.82', 'percent_change' => '0.80', 'timestamp' => 1786089600],
        'BADSYM' => ['code' => 404, 'message' => 'symbol not found', 'status' => 'error'],
    ]))]);

    $quotes = $c->quotes(['AAPL', 'BADSYM']);

    expect($quotes)->toHaveCount(1)
        ->and($quotes[0]->ticker)->toBe('AAPL');
});

it('sends the api key and the comma-joined symbol list', function (): void {
    $captured = null;
    $stack = HandlerStack::create(new MockHandler([new Response(200, [], '{}')]));
    $stack->push(function (callable $next) use (&$captured): callable {
        return function ($request, array $options) use ($next, &$captured) {
            $captured = $request;

            return $next($request, $options);
        };
    });

    (new TwelveDataClient(new Client(['handler' => $stack]), 'test-key', 'https://api.twelvedata.com'))
        ->quotes(['AAPL', 'MSFT']);

    parse_str($captured->getUri()->getQuery(), $query);

    expect($query['symbol'])->toBe('AAPL,MSFT')
        ->and($query['apikey'])->toBe('test-key');
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/pest tests/Unit/Upstream tests/Feature/Upstream`
Expected: FAIL — classes not found.

- [ ] **Step 3: Write `Quote`**

Create `app/Services/Upstream/DTO/Quote.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Upstream\DTO;

use App\Enums\TickSource;
use Carbon\CarbonImmutable;

final readonly class Quote
{
    public function __construct(
        public string $ticker,
        public string $price,
        public ?string $dayChange,
        public ?string $dayChangePct,
        public TickSource $source,
        public CarbonImmutable $quotedAt,
        public CarbonImmutable $receivedAt,
    ) {}

    /**
     * Milliseconds between the upstream's quote timestamp and our receipt.
     * Clamped at zero: the upstream stamps with its own clock, and a skew that
     * puts quotedAt in our future must not poison the p50/p95 lag window.
     */
    public function lagMs(): int
    {
        $ms = (int) round(($this->receivedAt->getPreciseTimestamp(3) - $this->quotedAt->getPreciseTimestamp(3)));

        return max(0, $ms);
    }

    /**
     * @return array{
     *     symbol_id: int, price: string, day_change: string|null,
     *     day_change_pct: string|null, source: string,
     *     quoted_at: string, received_at: string
     * }
     */
    public function toTickRow(int $symbolId): array
    {
        return [
            'symbol_id' => $symbolId,
            'price' => $this->price,
            'day_change' => $this->dayChange,
            'day_change_pct' => $this->dayChangePct,
            'source' => $this->source->value,
            // Pre-formatted with microseconds on purpose. Laravel's query
            // grammar formats DateTimeInterface bindings as 'Y-m-d H:i:s' with
            // no fractional part, so passing Carbon here would silently
            // truncate and destroy the lag this row exists to record.
            'quoted_at' => $this->quotedAt->format('Y-m-d H:i:s.u'),
            'received_at' => $this->receivedAt->format('Y-m-d H:i:s.u'),
        ];
    }
}
```

- [ ] **Step 4: Write the driver interface and the auth exception**

Create `app/Services/Upstream/UpstreamDriver.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Upstream;

use App\Enums\DriverState;

interface UpstreamDriver
{
    public function name(): DriverState;

    /**
     * @param  list<string>  $tickers
     * @param  callable(\App\Services\Upstream\DTO\Quote): void  $onQuote
     */
    public function start(array $tickers, callable $onQuote): void;

    /**
     * One iteration of scheduled work. Must not block: a push driver checks
     * liveness here and does no I/O at all.
     */
    public function tick(): void;

    public function stop(): void;

    public function isHealthy(): bool;

    public function lastError(): ?string;
}
```

Create `app/Services/Upstream/Exceptions/UpstreamAuthException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Upstream\Exceptions;

use RuntimeException;

/**
 * The upstream rejected our API key. On a Twelve Data trial this is the
 * expected outcome for the WebSocket feed, not an exceptional one — callers
 * demote to polling rather than crashing.
 */
final class UpstreamAuthException extends RuntimeException {}
```

- [ ] **Step 5: Write `TwelveDataClient`**

Create `app/Services/Upstream/TwelveDataClient.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Upstream;

use App\Enums\TickSource;
use App\Services\Upstream\DTO\Quote;
use App\Services\Upstream\Exceptions\UpstreamAuthException;
use Carbon\CarbonImmutable;
use GuzzleHttp\ClientInterface;

final readonly class TwelveDataClient
{
    public function __construct(
        private ClientInterface $http,
        private string $apiKey,
        private string $restUrl,
    ) {}

    /**
     * @param  list<string>  $tickers
     * @return list<Quote>
     *
     * @throws UpstreamAuthException
     */
    public function quotes(array $tickers): array
    {
        if ($tickers === []) {
            return [];
        }

        $response = $this->http->request('GET', $this->restUrl.'/quote', [
            'query' => [
                'symbol' => implode(',', $tickers),
                'apikey' => $this->apiKey,
            ],
            'timeout' => 10,
        ]);

        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $response->getBody(), true) ?: [];

        $this->guardAgainstAuthFailure($payload);

        // A single symbol comes back as a bare object; a batch comes back
        // keyed by ticker. Normalise to a list of rows.
        $rows = isset($payload['symbol']) ? [$payload] : array_values($payload);

        $receivedAt = CarbonImmutable::now();
        $quotes = [];

        foreach ($rows as $row) {
            if (! is_array($row) || ! isset($row['symbol'], $row['close'])) {
                continue; // upstream reported an error for this symbol; keep the rest
            }

            $quotes[] = new Quote(
                ticker: (string) $row['symbol'],
                price: (string) $row['close'],
                dayChange: isset($row['change']) ? (string) $row['change'] : null,
                dayChangePct: isset($row['percent_change']) ? (string) $row['percent_change'] : null,
                source: TickSource::Polling,
                quotedAt: isset($row['timestamp'])
                    ? CarbonImmutable::createFromTimestampUTC((int) $row['timestamp'])
                    : $receivedAt,
                receivedAt: $receivedAt,
            );
        }

        return $quotes;
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws UpstreamAuthException
     */
    private function guardAgainstAuthFailure(array $payload): void
    {
        $code = isset($payload['code']) ? (int) $payload['code'] : null;

        if (($payload['status'] ?? null) === 'error' && in_array($code, [401, 403], true)) {
            throw new UpstreamAuthException((string) ($payload['message'] ?? 'upstream rejected the api key'));
        }
    }
}
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `vendor/bin/pest tests/Unit/Upstream tests/Feature/Upstream`
Expected: PASS, 9 tests.

- [ ] **Step 7: Run the gates and commit**

```bash
vendor/bin/pint
vendor/bin/phpstan analyse --memory-limit=512M
vendor/bin/pest
git add -A
git commit -m "feat: add the quote DTO, driver interface and twelve data client

Prices stay strings end to end and tick rows carry microsecond-formatted
timestamps, because Laravel's query grammar truncates DateTimeInterface
bindings to whole seconds. An auth rejection raises a typed exception rather
than a generic failure: on a trial key it is the expected path to demotion.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 3: `PollingDriver`

**Files:**
- Create: `app/Services/Upstream/PollingDriver.php`
- Modify: `app/Providers/TapehouseServiceProvider.php`
- Test: `tests/Feature/Upstream/PollingDriverTest.php`

**Interfaces:**
- Consumes: `UpstreamDriver`, `TwelveDataClient`, `Quote` (Task 2), `CreditBudget` (Task 1)
- Produces: `PollingDriver::__construct(TwelveDataClient $client, CreditBudget $budget, Connection $redis, int $batchSize, int $intervalSeconds)` implementing `UpstreamDriver`. Task 5's `DriverManager` constructs and supervises it.

The cursor behaviour is the point of this task. Under starvation the driver must rotate, not stall.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Upstream/PollingDriverTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\DriverState;
use App\Services\Budget\CreditBudget;
use App\Services\Upstream\DTO\Quote;
use App\Services\Upstream\PollingDriver;
use App\Services\Upstream\TwelveDataClient;
use Carbon\CarbonImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Redis;

/** Builds a response echoing whatever symbols were asked for. */
function pollingResponses(int $count): array
{
    return array_fill(0, $count, new Response(200, [], json_encode([])));
}

/**
 * Records the symbol slice of every outgoing request. An object, not an array
 * by reference — a reference smuggled through a return value is fragile and
 * PHPStan cannot follow it.
 */
final class RequestRecorder
{
    /** @var list<list<string>> */
    public array $slices = [];
}

function driverWith(MockHandler $handler, RequestRecorder $recorder, int $capacity = 8, int $batch = 4): PollingDriver
{
    $stack = HandlerStack::create($handler);
    $stack->push(function (callable $next) use ($recorder): callable {
        return function ($request, array $options) use ($next, $recorder) {
            parse_str($request->getUri()->getQuery(), $q);
            $recorder->slices[] = explode(',', (string) $q['symbol']);

            return $next($request, $options);
        };
    });

    return new PollingDriver(
        new TwelveDataClient(new Client(['handler' => $stack]), 'test-key', 'https://api.twelvedata.com'),
        new CreditBudget(Redis::connection(), $capacity, $capacity),
        Redis::connection(),
        $batch,
        0, // no interval throttle in tests
    );
}

beforeEach(function (): void {
    Redis::connection()->flushdb();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-10 12:00:00'));
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('reports itself as the polling driver', function (): void {
    $driver = driverWith(new MockHandler(pollingResponses(1)), new RequestRecorder);

    expect($driver->name())->toBe(DriverState::Polling);
});

it('polls a slice no larger than the batch size', function (): void {
    $r = new RequestRecorder;
    $driver = driverWith(new MockHandler(pollingResponses(1)), $r, batch: 4);
    $driver->start(['A', 'B', 'C', 'D', 'E', 'F'], fn (Quote $q) => null);

    $driver->tick();

    expect($r->slices[0])->toBe(['A', 'B', 'C', 'D']);
});

it('advances the cursor so the next tick polls the next slice', function (): void {
    $r = new RequestRecorder;
    $driver = driverWith(new MockHandler(pollingResponses(2)), $r, batch: 4);
    $driver->start(['A', 'B', 'C', 'D', 'E', 'F'], fn (Quote $q) => null);

    $driver->tick();
    $driver->tick();

    expect($r->slices[1])->toBe(['E', 'F']);
});

it('wraps the cursor around the end of the list', function (): void {
    $r = new RequestRecorder;
    $driver = driverWith(new MockHandler(pollingResponses(3)), $r, batch: 4);
    $driver->start(['A', 'B', 'C', 'D', 'E', 'F'], fn (Quote $q) => null);

    $driver->tick();
    $driver->tick();
    $driver->tick();

    expect($r->slices[2])->toBe(['A', 'B', 'C', 'D']);
});

it('advances the cursor by the GRANTED count, not the requested slice', function (): void {
    // Capacity 8, batch 4. Spend 6 up front, leaving 2. The first tick may
    // only poll 2 symbols, so the next tick must resume at the third — not
    // skip to the fifth, which would starve C and D forever.
    $r = new RequestRecorder;
    $driver = driverWith(new MockHandler(pollingResponses(2)), $r, capacity: 8, batch: 4);
    (new CreditBudget(Redis::connection(), 8, 8))->tryConsume(6);

    $driver->start(['A', 'B', 'C', 'D', 'E', 'F'], fn (Quote $q) => null);

    $driver->tick();

    expect($r->slices[0])->toBe(['A', 'B']);
});

it('covers every symbol across successive starved passes', function (): void {
    $r = new RequestRecorder;
    $driver = driverWith(new MockHandler(pollingResponses(6)), $r, capacity: 2, batch: 4);
    $driver->start(['A', 'B', 'C', 'D', 'E', 'F'], fn (Quote $q) => null);

    // Two tokens per pass, refilling fully between passes.
    for ($pass = 0; $pass < 3; $pass++) {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-10 12:00:00')->addMinutes($pass + 1));
        $driver->tick();
    }

    expect(array_merge(...$r->slices))->toBe(['A', 'B', 'C', 'D', 'E', 'F']);
});

it('makes no request at all when the budget is empty', function (): void {
    $r = new RequestRecorder;
    $driver = driverWith(new MockHandler(pollingResponses(1)), $r, capacity: 8, batch: 4);
    (new CreditBudget(Redis::connection(), 8, 8))->tryConsume(8);

    $driver->start(['A', 'B'], fn (Quote $q) => null);
    $driver->tick();

    expect($r->slices)->toBeEmpty();
});

it('hands each parsed quote to the callback', function (): void {
    $handler = new MockHandler([new Response(200, [], json_encode([
        'AAPL' => ['symbol' => 'AAPL', 'close' => '228.41', 'change' => '1.82', 'percent_change' => '0.80', 'timestamp' => 1786089600],
    ]))]);
    $driver = driverWith($handler, new RequestRecorder, batch: 4);

    $received = [];
    $driver->start(['AAPL'], function (Quote $q) use (&$received): void {
        $received[] = $q;
    });
    $driver->tick();

    expect($received)->toHaveCount(1)
        ->and($received[0]->ticker)->toBe('AAPL');
});

it('stays healthy and records the error when a request fails', function (): void {
    $handler = new MockHandler([new Response(500, [], 'upstream exploded')]);
    $driver = driverWith($handler, new RequestRecorder, batch: 4);
    $driver->start(['AAPL'], fn (Quote $q) => null);

    $driver->tick();

    // Polling is the fallback of last resort. A transient 500 must not make it
    // report unhealthy, or the manager would have nowhere left to demote to.
    expect($driver->isHealthy())->toBeTrue()
        ->and($driver->lastError())->not->toBeNull();
});

it('reports unhealthy when the key is rejected', function (): void {
    $handler = new MockHandler([new Response(200, [], json_encode([
        'code' => 401, 'message' => '**api_key** not valid', 'status' => 'error',
    ]))]);
    $driver = driverWith($handler, new RequestRecorder, batch: 4);
    $driver->start(['AAPL'], fn (Quote $q) => null);

    $driver->tick();

    expect($driver->isHealthy())->toBeFalse()
        ->and($driver->lastError())->toContain('api_key');
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/pest tests/Feature/Upstream/PollingDriverTest.php`
Expected: FAIL — `PollingDriver` not found.

- [ ] **Step 3: Write `PollingDriver`**

Create `app/Services/Upstream/PollingDriver.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Upstream;

use App\Enums\DriverState;
use App\Services\Budget\CreditBudget;
use App\Services\Upstream\Exceptions\UpstreamAuthException;
use Carbon\CarbonImmutable;
use Illuminate\Redis\Connections\Connection;
use Throwable;

final class PollingDriver implements UpstreamDriver
{
    private const CURSOR_KEY = 'tape:poll:cursor';

    /** @var list<string> */
    private array $tickers = [];

    /** @var (callable(\App\Services\Upstream\DTO\Quote): void)|null */
    private $onQuote = null;

    private ?string $lastError = null;

    private bool $authRejected = false;

    private ?CarbonImmutable $lastPolledAt = null;

    public function __construct(
        private readonly TwelveDataClient $client,
        private readonly CreditBudget $budget,
        private readonly Connection $redis,
        private readonly int $batchSize,
        private readonly int $intervalSeconds,
    ) {}

    public function name(): DriverState
    {
        return DriverState::Polling;
    }

    public function start(array $tickers, callable $onQuote): void
    {
        $this->tickers = array_values($tickers);
        $this->onQuote = $onQuote;
        $this->authRejected = false;
        $this->lastError = null;
    }

    public function tick(): void
    {
        if ($this->tickers === [] || $this->onQuote === null || ! $this->intervalElapsed()) {
            return;
        }

        $cursor = $this->cursor();
        $slice = $this->sliceFrom($cursor);

        // One credit per symbol, not per request. A partial grant polls fewer
        // symbols rather than overspending or stalling the whole slice.
        $granted = $this->budget->tryConsume(count($slice));

        if ($granted <= 0) {
            return;
        }

        $slice = array_slice($slice, 0, $granted);
        $this->lastPolledAt = CarbonImmutable::now();

        // Advance by what was actually polled. Advancing by the requested
        // slice would permanently starve the symbols the budget could not
        // cover on this pass.
        $this->setCursor(($cursor + count($slice)) % max(1, count($this->tickers)));

        try {
            foreach ($this->client->quotes($slice) as $quote) {
                ($this->onQuote)($quote);
            }

            $this->lastError = null;
        } catch (UpstreamAuthException $e) {
            $this->authRejected = true;
            $this->lastError = $e->getMessage();
        } catch (Throwable $e) {
            // Transient failures do not make polling unhealthy: it is the
            // fallback of last resort and there is nowhere to demote to.
            $this->lastError = $e->getMessage();
        }
    }

    public function stop(): void
    {
        $this->tickers = [];
        $this->onQuote = null;
    }

    public function isHealthy(): bool
    {
        return ! $this->authRejected;
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * @return list<string>
     */
    private function sliceFrom(int $cursor): array
    {
        $count = count($this->tickers);

        if ($count === 0) {
            return [];
        }

        $slice = [];

        for ($i = 0; $i < min($this->batchSize, $count); $i++) {
            $slice[] = $this->tickers[($cursor + $i) % $count];
        }

        return $slice;
    }

    private function cursor(): int
    {
        $count = count($this->tickers);

        if ($count === 0) {
            return 0;
        }

        return ((int) ($this->redis->get(self::CURSOR_KEY) ?? 0)) % $count;
    }

    private function setCursor(int $cursor): void
    {
        $this->redis->set(self::CURSOR_KEY, (string) $cursor);
    }

    private function intervalElapsed(): bool
    {
        if ($this->intervalSeconds <= 0 || $this->lastPolledAt === null) {
            return true;
        }

        return CarbonImmutable::now()->diffInSeconds($this->lastPolledAt, true) >= $this->intervalSeconds;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/pest tests/Feature/Upstream/PollingDriverTest.php`
Expected: PASS, 11 tests.

If "covers every symbol across successive starved passes" fails, the cursor is advancing by the requested slice instead of the granted count. That is the exact bug the test exists to catch.

- [ ] **Step 5: Run the gates and commit**

```bash
vendor/bin/pint
vendor/bin/phpstan analyse --memory-limit=512M
vendor/bin/pest
git add -A
git commit -m "feat: add the credit-budgeted polling driver

Acquires one token per symbol before every request and honours partial
grants, advancing the rotating cursor by what was actually polled. Under a
starved budget the watchlist rotates instead of the tail starving. A transient
HTTP failure does not mark the driver unhealthy — polling is the fallback of
last resort and has nowhere to demote to.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 4: `WebSocketDriver` and `SimulatedDriver`

**Files:**
- Create: `app/Services/Upstream/WebSocketDriver.php`
- Create: `app/Services/Upstream/SimulatedDriver.php`
- Test: `tests/Feature/Upstream/WebSocketDriverTest.php`, `tests/Feature/Upstream/SimulatedDriverTest.php`

**Interfaces:**
- Consumes: `UpstreamDriver`, `Quote`, `TickSource`, `DriverState`
- Produces:
  - `WebSocketDriver::__construct(Connector $connector, string $wsUrl, string $apiKey, int $silenceSeconds = 90)` implementing `UpstreamDriver`; also `handleMessage(string $raw): void` and `handleFailure(string $error): void`, which are public so the tests can drive the socket lifecycle without a real server
  - `SimulatedDriver::__construct(array $seedPrices, int $intervalMs)` implementing `UpstreamDriver`

`WebSocketDriver::tick()` performs **no I/O** — it evaluates liveness only. The connection is driven by the ReactPHP loop, and messages arrive through `handleMessage`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Upstream/WebSocketDriverTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\DriverState;
use App\Enums\TickSource;
use App\Services\Upstream\DTO\Quote;
use App\Services\Upstream\WebSocketDriver;
use Carbon\CarbonImmutable;
use Ratchet\Client\Connector;

function wsDriver(int $silence = 90): WebSocketDriver
{
    return new WebSocketDriver(
        new Connector,
        'wss://ws.twelvedata.com/v1/quotes/price',
        'test-key',
        $silence,
    );
}

beforeEach(fn () => CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-10 12:00:00')));
afterEach(fn () => CarbonImmutable::setTestNow());

it('reports itself as the websocket driver', function (): void {
    expect(wsDriver()->name())->toBe(DriverState::WebSocket);
});

it('parses a price event into a quote', function (): void {
    $driver = wsDriver();
    $received = [];
    $driver->start(['AAPL'], function (Quote $q) use (&$received): void {
        $received[] = $q;
    });

    $driver->handleMessage(json_encode([
        'event' => 'price',
        'symbol' => 'AAPL',
        'price' => 228.41,
        'timestamp' => 1786089600,
    ]));

    expect($received)->toHaveCount(1)
        ->and($received[0]->ticker)->toBe('AAPL')
        ->and($received[0]->price)->toBe('228.41')
        ->and($received[0]->source)->toBe(TickSource::WebSocket);
});

it('ignores non-price events', function (): void {
    $driver = wsDriver();
    $received = [];
    $driver->start(['AAPL'], function (Quote $q) use (&$received): void {
        $received[] = $q;
    });

    $driver->handleMessage(json_encode(['event' => 'subscribe-status', 'status' => 'ok']));
    $driver->handleMessage(json_encode(['event' => 'heartbeat']));

    expect($received)->toBeEmpty()
        ->and($driver->isHealthy())->toBeTrue();
});

it('goes unhealthy immediately on an auth rejection', function (): void {
    $driver = wsDriver();
    $driver->start(['AAPL'], fn (Quote $q) => null);

    // The expected outcome on a Twelve Data trial key: the socket needs Pro.
    $driver->handleMessage(json_encode([
        'event' => 'subscribe-status',
        'status' => 'error',
        'messages' => ['**api_key** not valid or Pro plan required'],
    ]));

    expect($driver->isHealthy())->toBeFalse()
        ->and($driver->lastError())->toContain('api_key');
});

it('stays healthy through fewer than three consecutive failures', function (): void {
    $driver = wsDriver();
    $driver->start(['AAPL'], fn (Quote $q) => null);

    $driver->handleFailure('connection reset');
    $driver->handleFailure('connection reset');

    expect($driver->isHealthy())->toBeTrue()
        ->and($driver->consecutiveFailures())->toBe(2);
});

it('goes unhealthy on the third consecutive failure', function (): void {
    $driver = wsDriver();
    $driver->start(['AAPL'], fn (Quote $q) => null);

    $driver->handleFailure('connection reset');
    $driver->handleFailure('connection reset');
    $driver->handleFailure('connection reset');

    expect($driver->isHealthy())->toBeFalse();
});

it('resets the failure count when a message arrives', function (): void {
    $driver = wsDriver();
    $driver->start(['AAPL'], fn (Quote $q) => null);

    $driver->handleFailure('reset');
    $driver->handleFailure('reset');
    $driver->handleMessage(json_encode(['event' => 'price', 'symbol' => 'AAPL', 'price' => 1.0, 'timestamp' => 1786089600]));

    expect($driver->consecutiveFailures())->toBe(0)
        ->and($driver->isHealthy())->toBeTrue();
});

it('goes unhealthy after prolonged silence', function (): void {
    $driver = wsDriver(silence: 90);
    $driver->start(['AAPL'], fn (Quote $q) => null);
    $driver->handleMessage(json_encode(['event' => 'price', 'symbol' => 'AAPL', 'price' => 1.0, 'timestamp' => 1786089600]));

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-10 12:01:00'));
    $driver->tick();
    expect($driver->isHealthy())->toBeTrue();

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-10 12:01:31'));
    $driver->tick();

    expect($driver->isHealthy())->toBeFalse()
        ->and($driver->lastError())->toContain('silent');
});

it('builds a subscribe payload for its ticker list', function (): void {
    $driver = wsDriver();
    $driver->start(['AAPL', 'EUR/USD'], fn (Quote $q) => null);

    expect($driver->subscribePayload())->toBe(json_encode([
        'action' => 'subscribe',
        'params' => ['symbols' => 'AAPL,EUR/USD'],
    ]));
});
```

Create `tests/Feature/Upstream/SimulatedDriverTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\DriverState;
use App\Enums\TickSource;
use App\Services\Upstream\DTO\Quote;
use App\Services\Upstream\SimulatedDriver;
use Carbon\CarbonImmutable;

beforeEach(fn () => CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-10 12:00:00')));
afterEach(fn () => CarbonImmutable::setTestNow());

it('reports itself as simulated and never as live', function (): void {
    // The ops panel must be able to say `simulated` in plain text. A driver
    // that reported websocket or polling here would be lying to the operator.
    $driver = new SimulatedDriver(['AAPL' => '228.41'], 0);

    expect($driver->name())->toBe(DriverState::Simulated);
});

it('emits quotes tagged as simulated', function (): void {
    $driver = new SimulatedDriver(['AAPL' => '228.41'], 0);
    $received = [];
    $driver->start(['AAPL'], function (Quote $q) use (&$received): void {
        $received[] = $q;
    });

    $driver->tick();

    expect($received)->toHaveCount(1)
        ->and($received[0]->source)->toBe(TickSource::Simulated)
        ->and($received[0]->ticker)->toBe('AAPL');
});

it('walks the price rather than repeating the seed', function (): void {
    $driver = new SimulatedDriver(['AAPL' => '228.41'], 0);
    $prices = [];
    $driver->start(['AAPL'], function (Quote $q) use (&$prices): void {
        $prices[] = $q->price;
    });

    for ($i = 0; $i < 20; $i++) {
        $driver->tick();
    }

    expect(array_unique($prices))->not->toHaveCount(1);
});

it('keeps prices positive across a long walk', function (): void {
    $driver = new SimulatedDriver(['PENNY' => '0.01'], 0);
    $prices = [];
    $driver->start(['PENNY'], function (Quote $q) use (&$prices): void {
        $prices[] = (float) $q->price;
    });

    for ($i = 0; $i < 500; $i++) {
        $driver->tick();
    }

    expect(min($prices))->toBeGreaterThan(0.0);
});

it('respects its interval', function (): void {
    $driver = new SimulatedDriver(['AAPL' => '228.41'], 620);
    $count = 0;
    $driver->start(['AAPL'], function (Quote $q) use (&$count): void {
        $count++;
    });

    $driver->tick();
    $driver->tick();
    expect($count)->toBe(1);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-10 12:00:01'));
    $driver->tick();

    expect($count)->toBe(2);
});

it('is always healthy', function (): void {
    $driver = new SimulatedDriver(['AAPL' => '228.41'], 0);

    expect($driver->isHealthy())->toBeTrue()
        ->and($driver->lastError())->toBeNull();
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/Upstream/WebSocketDriverTest.php tests/Feature/Upstream/SimulatedDriverTest.php`
Expected: FAIL — classes not found.

- [ ] **Step 3: Write `WebSocketDriver`**

Create `app/Services/Upstream/WebSocketDriver.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Upstream;

use App\Enums\DriverState;
use App\Enums\TickSource;
use App\Services\Upstream\DTO\Quote;
use Carbon\CarbonImmutable;
use Ratchet\Client\Connector;
use Ratchet\Client\WebSocket;
use Throwable;

final class WebSocketDriver implements UpstreamDriver
{
    /** @var list<string> */
    private array $tickers = [];

    /** @var (callable(Quote): void)|null */
    private $onQuote = null;

    private ?WebSocket $socket = null;

    private ?string $lastError = null;

    private int $consecutiveFailures = 0;

    private bool $authRejected = false;

    private ?CarbonImmutable $lastMessageAt = null;

    public function __construct(
        private readonly Connector $connector,
        private readonly string $wsUrl,
        private readonly string $apiKey,
        private readonly int $silenceSeconds = 90,
        private readonly int $failureThreshold = 3,
    ) {}

    public function name(): DriverState
    {
        return DriverState::WebSocket;
    }

    public function start(array $tickers, callable $onQuote): void
    {
        $this->tickers = array_values($tickers);
        $this->onQuote = $onQuote;
        $this->authRejected = false;
        $this->consecutiveFailures = 0;
        $this->lastError = null;
        $this->lastMessageAt = CarbonImmutable::now();

        $this->connect();
    }

    /**
     * No I/O. The socket is driven by the ReactPHP loop; this only asks
     * whether the connection still looks alive.
     */
    public function tick(): void
    {
        if ($this->lastMessageAt === null || $this->authRejected) {
            return;
        }

        $silentFor = CarbonImmutable::now()->diffInSeconds($this->lastMessageAt, true);

        if ($silentFor > $this->silenceSeconds) {
            $this->lastError = sprintf('socket silent for %ds', (int) $silentFor);
            $this->consecutiveFailures = $this->failureThreshold;
        }
    }

    public function stop(): void
    {
        $this->socket?->close();
        $this->socket = null;
        $this->tickers = [];
        $this->onQuote = null;
    }

    public function isHealthy(): bool
    {
        return ! $this->authRejected && $this->consecutiveFailures < $this->failureThreshold;
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    public function consecutiveFailures(): int
    {
        return $this->consecutiveFailures;
    }

    public function subscribePayload(): string
    {
        return json_encode([
            'action' => 'subscribe',
            'params' => ['symbols' => implode(',', $this->tickers)],
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Public so the loop and the tests can feed frames in without a live
     * server standing between the parser and its test.
     */
    public function handleMessage(string $raw): void
    {
        $this->lastMessageAt = CarbonImmutable::now();

        /** @var array<string, mixed>|null $payload */
        $payload = json_decode($raw, true);

        if (! is_array($payload)) {
            return;
        }

        if (($payload['status'] ?? null) === 'error') {
            $messages = $payload['messages'] ?? [$payload['message'] ?? 'upstream rejected the subscription'];
            $this->authRejected = true;
            $this->lastError = is_array($messages) ? implode('; ', array_map('strval', $messages)) : (string) $messages;

            return;
        }

        if (($payload['event'] ?? null) !== 'price' || ! isset($payload['symbol'], $payload['price'])) {
            $this->consecutiveFailures = 0;

            return;
        }

        $this->consecutiveFailures = 0;

        $receivedAt = CarbonImmutable::now();

        ($this->onQuote)(new Quote(
            ticker: (string) $payload['symbol'],
            price: $this->stringifyPrice($payload['price']),
            dayChange: isset($payload['day_change']) ? $this->stringifyPrice($payload['day_change']) : null,
            dayChangePct: isset($payload['percent_change']) ? $this->stringifyPrice($payload['percent_change']) : null,
            source: TickSource::WebSocket,
            quotedAt: isset($payload['timestamp'])
                ? CarbonImmutable::createFromTimestampUTC((int) $payload['timestamp'])
                : $receivedAt,
            receivedAt: $receivedAt,
        ));
    }

    public function handleFailure(string $error): void
    {
        $this->consecutiveFailures++;
        $this->lastError = $error;
        $this->socket = null;
    }

    private function connect(): void
    {
        $url = sprintf('%s?apikey=%s', $this->wsUrl, urlencode($this->apiKey));

        try {
            ($this->connector)($url)->then(
                function (WebSocket $socket): void {
                    $this->socket = $socket;
                    $this->consecutiveFailures = 0;
                    $this->lastMessageAt = CarbonImmutable::now();

                    $socket->on('message', function ($message): void {
                        $this->handleMessage((string) $message);
                    });

                    $socket->on('close', function ($code = null): void {
                        $this->handleFailure(sprintf('socket closed %s', $code ?? 'unknown'));
                    });

                    $socket->send($this->subscribePayload());
                },
                function (Throwable $e): void {
                    $this->handleFailure($e->getMessage());
                },
            );
        } catch (Throwable $e) {
            $this->handleFailure($e->getMessage());
        }
    }

    /**
     * Twelve Data sends prices as JSON numbers. Render without exponent or
     * trailing float noise so the value that reaches Postgres is the value
     * that arrived.
     */
    private function stringifyPrice(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        return rtrim(rtrim(number_format((float) $value, 8, '.', ''), '0'), '.') ?: '0';
    }
}
```

- [ ] **Step 4: Write `SimulatedDriver`**

Create `app/Services/Upstream/SimulatedDriver.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Upstream;

use App\Enums\DriverState;
use App\Enums\TickSource;
use App\Services\Upstream\DTO\Quote;
use Carbon\CarbonImmutable;

/**
 * Generates a random walk so the tape has enough ticks to exercise the flash
 * during development and in the deployed demo, where an 8-credit-per-minute
 * trial key cannot.
 *
 * It reports itself as `simulated` everywhere — driver state, tick source and
 * feed events — and never as websocket or polling. The demo may be driven by
 * generated data; it must never claim that data is live.
 */
final class SimulatedDriver implements UpstreamDriver
{
    /** @var array<string, float> */
    private array $prices;

    /** @var list<string> */
    private array $tickers = [];

    /** @var (callable(Quote): void)|null */
    private $onQuote = null;

    private ?CarbonImmutable $lastTickAt = null;

    /**
     * @param  array<string, string>  $seedPrices  ticker => opening price
     */
    public function __construct(array $seedPrices, private readonly int $intervalMs)
    {
        $this->prices = array_map(static fn (string $p): float => (float) $p, $seedPrices);
    }

    public function name(): DriverState
    {
        return DriverState::Simulated;
    }

    public function start(array $tickers, callable $onQuote): void
    {
        $this->tickers = array_values($tickers);
        $this->onQuote = $onQuote;
        $this->lastTickAt = null;

        foreach ($this->tickers as $ticker) {
            $this->prices[$ticker] ??= 100.0;
        }
    }

    public function tick(): void
    {
        if ($this->tickers === [] || $this->onQuote === null || ! $this->intervalElapsed()) {
            return;
        }

        $this->lastTickAt = CarbonImmutable::now();

        $ticker = $this->tickers[random_int(0, count($this->tickers) - 1)];
        $previous = $this->prices[$ticker];

        $step = $previous * (random_int(5, 900) / 1_000_000) * (random_int(0, 1) === 1 ? 1 : -1);
        $price = max(0.00001, $previous + $step);

        $this->prices[$ticker] = $price;

        $now = CarbonImmutable::now();

        ($this->onQuote)(new Quote(
            ticker: $ticker,
            price: number_format($price, 8, '.', ''),
            dayChange: number_format($price - $previous, 8, '.', ''),
            dayChangePct: number_format((($price - $previous) / $previous) * 100, 4, '.', ''),
            source: TickSource::Simulated,
            quotedAt: $now,
            receivedAt: $now,
        ));
    }

    public function stop(): void
    {
        $this->tickers = [];
        $this->onQuote = null;
    }

    public function isHealthy(): bool
    {
        return true;
    }

    public function lastError(): ?string
    {
        return null;
    }

    private function intervalElapsed(): bool
    {
        if ($this->intervalMs <= 0 || $this->lastTickAt === null) {
            return true;
        }

        $elapsedMs = (CarbonImmutable::now()->getPreciseTimestamp(3) - $this->lastTickAt->getPreciseTimestamp(3));

        return $elapsedMs >= $this->intervalMs;
    }
}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `vendor/bin/pest tests/Feature/Upstream/WebSocketDriverTest.php tests/Feature/Upstream/SimulatedDriverTest.php`
Expected: PASS, 15 tests.

- [ ] **Step 6: Run the gates and commit**

```bash
vendor/bin/pint
vendor/bin/phpstan analyse --memory-limit=512M
vendor/bin/pest
git add -A
git commit -m "feat: add the websocket and simulated drivers

The websocket driver does no I/O in tick() — it evaluates liveness only, with
the socket driven by the ReactPHP loop. Auth rejection marks it unhealthy
immediately rather than throwing, because on a trial key it is the expected
route to demotion. The simulated driver reports itself as simulated
everywhere so generated ticks are never presented as live.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 5: `DriverManager` and `FeedControl`

**Files:**
- Create: `app/Services/Upstream/DriverManager.php`
- Create: `app/Services/Control/FeedControl.php`
- Modify: `app/Providers/TapehouseServiceProvider.php`
- Test: `tests/Feature/Upstream/DriverManagerTest.php`, `tests/Feature/Control/FeedControlTest.php`

**Interfaces:**
- Consumes: all three drivers, `FeedEvent` model, `DriverState`, `FeedEventLevel`
- Produces:
  - `FeedControl::__construct(Connection $redis)`, `stop(): void`, `start(): void`, `isStopped(): bool`
  - `DriverManager::__construct(UpstreamDriver $primary, UpstreamDriver $fallback, FeedControl $control, Connection $redis, array $promotionBackoff)`
  - `boot(array $tickers, callable $onQuote): void`, `current(): UpstreamDriver`, `supervise(): void`, `state(): DriverState`, `since(): CarbonImmutable`, `reconnects(): int`, `stopAll(): void`

Every transition writes a `feed_events` row, updates `tape:driver:state`, and — from Plan 3 — broadcasts. Plan 3 injects the broadcaster; Plan 2 leaves the transition hook as a method later extended.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Control/FeedControlTest.php`:

```php
<?php

declare(strict_types=1);

use App\Services\Control\FeedControl;
use Illuminate\Support\Facades\Redis;

beforeEach(fn () => Redis::connection()->flushdb());

it('runs by default', function (): void {
    expect((new FeedControl(Redis::connection()))->isStopped())->toBeFalse();
});

it('stops and starts across separate instances', function (): void {
    // The web process and the ingest loop are different processes — in
    // production, different containers — so the flag must live in Redis, not
    // in object state.
    (new FeedControl(Redis::connection()))->stop();

    expect((new FeedControl(Redis::connection()))->isStopped())->toBeTrue();

    (new FeedControl(Redis::connection()))->start();

    expect((new FeedControl(Redis::connection()))->isStopped())->toBeFalse();
});
```

Create `tests/Feature/Upstream/DriverManagerTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\DriverState;
use App\Models\FeedEvent;
use App\Services\Control\FeedControl;
use App\Services\Upstream\DriverManager;
use App\Services\Upstream\DTO\Quote;
use App\Services\Upstream\UpstreamDriver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Redis;

/**
 * A driver whose health the test controls directly. A named class, not an
 * anonymous one: the tests read `$primary->healthy`, and PHPStan cannot see
 * that property through the `UpstreamDriver` return type of a factory.
 */
final class FakeDriver implements UpstreamDriver
{
    public bool $healthy = true;

    public bool $started = false;

    public bool $stopped = false;

    public ?string $error = null;

    public function __construct(private readonly DriverState $state) {}

    public function name(): DriverState
    {
        return $this->state;
    }

    public function start(array $tickers, callable $onQuote): void
    {
        $this->started = true;
    }

    public function tick(): void {}

    public function stop(): void
    {
        $this->stopped = true;
    }

    public function isHealthy(): bool
    {
        return $this->healthy;
    }

    public function lastError(): ?string
    {
        return $this->error;
    }
}

function fakeDriver(DriverState $state): FakeDriver
{
    return new FakeDriver($state);
}

function manager(FakeDriver $primary, FakeDriver $fallback): DriverManager
{
    return new DriverManager(
        $primary,
        $fallback,
        new FeedControl(Redis::connection()),
        Redis::connection(),
        [60, 120, 300],
    );
}

beforeEach(function (): void {
    Redis::connection()->flushdb();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-10 12:00:00'));
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('boots on the primary driver', function (): void {
    $primary = fakeDriver(DriverState::WebSocket);
    $m = manager($primary, fakeDriver(DriverState::Polling));

    $m->boot(['AAPL'], fn (Quote $q) => null);

    expect($m->state())->toBe(DriverState::WebSocket)
        ->and($primary->started)->toBeTrue();
});

it('demotes to the fallback when the primary reports unhealthy', function (): void {
    $primary = fakeDriver(DriverState::WebSocket);
    $fallback = fakeDriver(DriverState::Polling);
    $m = manager($primary, $fallback);
    $m->boot(['AAPL'], fn (Quote $q) => null);

    $primary->healthy = false;
    $m->supervise();

    expect($m->state())->toBe(DriverState::Polling)
        ->and($fallback->started)->toBeTrue()
        ->and($primary->stopped)->toBeTrue();
});

it('writes a feed event for every transition', function (): void {
    $primary = fakeDriver(DriverState::WebSocket);
    $m = manager($primary, fakeDriver(DriverState::Polling));
    $m->boot(['AAPL'], fn (Quote $q) => null);

    $primary->healthy = false;
    $m->supervise();

    $event = FeedEvent::where('type', 'driver.demoted')->sole();

    expect($event->level->value)->toBe('warn')
        ->and($event->context['from'])->toBe('websocket')
        ->and($event->context['to'])->toBe('polling');
});

it('counts a reconnect per demotion', function (): void {
    $primary = fakeDriver(DriverState::WebSocket);
    $m = manager($primary, fakeDriver(DriverState::Polling));
    $m->boot(['AAPL'], fn (Quote $q) => null);

    expect($m->reconnects())->toBe(0);

    $primary->healthy = false;
    $m->supervise();

    expect($m->reconnects())->toBe(1);
});

it('does not promote before the first backoff elapses', function (): void {
    $primary = fakeDriver(DriverState::WebSocket);
    $m = manager($primary, fakeDriver(DriverState::Polling));
    $m->boot(['AAPL'], fn (Quote $q) => null);
    $primary->healthy = false;
    $m->supervise();

    $primary->healthy = true;
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-10 12:00:59'));
    $m->supervise();

    expect($m->state())->toBe(DriverState::Polling);
});

it('promotes once the first backoff has elapsed', function (): void {
    $primary = fakeDriver(DriverState::WebSocket);
    $m = manager($primary, fakeDriver(DriverState::Polling));
    $m->boot(['AAPL'], fn (Quote $q) => null);
    $primary->healthy = false;
    $m->supervise();

    $primary->healthy = true;
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-10 12:01:01'));
    $m->supervise();

    expect($m->state())->toBe(DriverState::WebSocket);
});

it('escalates the backoff on each successive demotion', function (): void {
    $primary = fakeDriver(DriverState::WebSocket);
    $m = manager($primary, fakeDriver(DriverState::Polling));
    $m->boot(['AAPL'], fn (Quote $q) => null);

    // First demotion → 60s window.
    $primary->healthy = false;
    $m->supervise();
    $primary->healthy = true;
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-10 12:01:01'));
    $m->supervise();
    expect($m->state())->toBe(DriverState::WebSocket);

    // Second demotion → 120s window, so 61s is not enough.
    $primary->healthy = false;
    $m->supervise();
    $primary->healthy = true;
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-10 12:02:02'));
    $m->supervise();
    expect($m->state())->toBe(DriverState::Polling);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-10 12:03:02'));
    $m->supervise();
    expect($m->state())->toBe(DriverState::WebSocket);
});

it('caps the backoff at the last configured value', function (): void {
    $primary = fakeDriver(DriverState::WebSocket);
    $m = manager($primary, fakeDriver(DriverState::Polling));
    $m->boot(['AAPL'], fn (Quote $q) => null);

    // Demote five times; the schedule has only three entries.
    for ($i = 0; $i < 5; $i++) {
        $primary->healthy = false;
        $m->supervise();
        $primary->healthy = true;
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(400));
        $m->supervise();
    }

    expect($m->currentBackoffSeconds())->toBe(300);
});

it('stops every driver when the control flag is set', function (): void {
    $primary = fakeDriver(DriverState::WebSocket);
    $m = manager($primary, fakeDriver(DriverState::Polling));
    $m->boot(['AAPL'], fn (Quote $q) => null);

    (new FeedControl(Redis::connection()))->stop();
    $m->supervise();

    expect($m->state())->toBe(DriverState::Stopped)
        ->and($primary->stopped)->toBeTrue()
        ->and(FeedEvent::where('type', 'feed.stopped')->count())->toBe(1);
});

it('resumes on the primary when the control flag clears', function (): void {
    $primary = fakeDriver(DriverState::WebSocket);
    $m = manager($primary, fakeDriver(DriverState::Polling));
    $m->boot(['AAPL'], fn (Quote $q) => null);
    $control = new FeedControl(Redis::connection());

    $control->stop();
    $m->supervise();
    $control->start();
    $m->supervise();

    expect($m->state())->toBe(DriverState::WebSocket);
});

it('publishes its state to redis for the ops panel', function (): void {
    $primary = fakeDriver(DriverState::WebSocket);
    $m = manager($primary, fakeDriver(DriverState::Polling));
    $m->boot(['AAPL'], fn (Quote $q) => null);

    $state = Redis::connection()->hgetall('tape:driver:state');

    expect($state['driver'])->toBe('websocket')
        ->and($state['reconnects'])->toBe('0');
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/Upstream/DriverManagerTest.php tests/Feature/Control/FeedControlTest.php`
Expected: FAIL — classes not found.

- [ ] **Step 3: Write `FeedControl`**

Create `app/Services/Control/FeedControl.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Control;

use Illuminate\Redis\Connections\Connection;

/**
 * The Stop feed button's backing state.
 *
 * The web request and the ingest loop are separate processes — separate
 * containers in production — so this cannot be a signal or in-process state.
 * A Redis key the loop reads each pass is the mechanism that works across both.
 */
final readonly class FeedControl
{
    private const KEY = 'tape:control:state';

    private const STOPPED = 'stopped';

    private const RUNNING = 'running';

    public function __construct(private Connection $redis) {}

    public function stop(): void
    {
        $this->redis->set(self::KEY, self::STOPPED);
    }

    public function start(): void
    {
        $this->redis->set(self::KEY, self::RUNNING);
    }

    public function isStopped(): bool
    {
        return $this->redis->get(self::KEY) === self::STOPPED;
    }
}
```

- [ ] **Step 4: Write `DriverManager`**

Create `app/Services/Upstream/DriverManager.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Upstream;

use App\Enums\DriverState;
use App\Enums\FeedEventLevel;
use App\Models\FeedEvent;
use App\Services\Control\FeedControl;
use Carbon\CarbonImmutable;
use Illuminate\Redis\Connections\Connection;

final class DriverManager
{
    private const STATE_KEY = 'tape:driver:state';

    private UpstreamDriver $current;

    /** @var list<string> */
    private array $tickers = [];

    /** @var (callable(DTO\Quote): void)|null */
    private $onQuote = null;

    private DriverState $state;

    private CarbonImmutable $since;

    private int $reconnects = 0;

    private int $demotions = 0;

    private ?CarbonImmutable $demotedAt = null;

    private bool $stopped = false;

    /**
     * @param  list<int>  $promotionBackoff
     */
    public function __construct(
        private readonly UpstreamDriver $primary,
        private readonly UpstreamDriver $fallback,
        private readonly FeedControl $control,
        private readonly Connection $redis,
        private readonly array $promotionBackoff,
    ) {
        $this->current = $primary;
        $this->state = $primary->name();
        $this->since = CarbonImmutable::now();
    }

    /**
     * @param  list<string>  $tickers
     * @param  callable(DTO\Quote): void  $onQuote
     */
    public function boot(array $tickers, callable $onQuote): void
    {
        $this->tickers = $tickers;
        $this->onQuote = $onQuote;
        $this->current = $this->primary;
        $this->transitionTo($this->primary, 'feed.started', FeedEventLevel::Info, 'ingest started');
        $this->current->start($tickers, $onQuote);
    }

    public function current(): UpstreamDriver
    {
        return $this->current;
    }

    public function state(): DriverState
    {
        return $this->state;
    }

    public function since(): CarbonImmutable
    {
        return $this->since;
    }

    public function reconnects(): int
    {
        return $this->reconnects;
    }

    public function currentBackoffSeconds(): int
    {
        $index = min(max(0, $this->demotions - 1), count($this->promotionBackoff) - 1);

        return $this->promotionBackoff[$index];
    }

    /**
     * One supervision pass: honour the control flag, demote an unhealthy
     * primary, promote a recovered one once its backoff has elapsed.
     */
    public function supervise(): void
    {
        if ($this->control->isStopped()) {
            if (! $this->stopped) {
                $this->current->stop();
                $this->stopped = true;
                $this->state = DriverState::Stopped;
                $this->since = CarbonImmutable::now();
                $this->record('feed.stopped', FeedEventLevel::Warn, 'feed stopped by operator', []);
                $this->publish();
            }

            return;
        }

        if ($this->stopped) {
            $this->stopped = false;
            $this->current = $this->primary;
            $this->transitionTo($this->primary, 'feed.started', FeedEventLevel::Info, 'feed resumed by operator');
            $this->current->start($this->tickers, $this->onQuote ?? static fn () => null);

            return;
        }

        if ($this->current === $this->primary && ! $this->primary->isHealthy()) {
            $this->demote();

            return;
        }

        if ($this->current === $this->fallback && $this->primary->isHealthy() && $this->backoffElapsed()) {
            $this->promote();
        }
    }

    public function stopAll(): void
    {
        $this->primary->stop();
        $this->fallback->stop();
    }

    private function demote(): void
    {
        $error = $this->primary->lastError();

        $this->primary->stop();
        $this->current = $this->fallback;
        $this->demotions++;
        $this->reconnects++;
        $this->demotedAt = CarbonImmutable::now();

        $this->transitionTo(
            $this->fallback,
            'driver.demoted',
            FeedEventLevel::Warn,
            sprintf('%s demoted → %s. %s', $this->primary->name()->value, $this->fallback->name()->value, $error ?? 'unhealthy'),
            ['from' => $this->primary->name()->value, 'to' => $this->fallback->name()->value, 'error' => $error],
        );

        $this->fallback->start($this->tickers, $this->onQuote ?? static fn () => null);
    }

    private function promote(): void
    {
        $this->fallback->stop();
        $this->current = $this->primary;
        $this->demotedAt = null;

        $this->transitionTo(
            $this->primary,
            'driver.promoted',
            FeedEventLevel::Info,
            sprintf('%s promoted → %s', $this->fallback->name()->value, $this->primary->name()->value),
            ['from' => $this->fallback->name()->value, 'to' => $this->primary->name()->value],
        );

        $this->primary->start($this->tickers, $this->onQuote ?? static fn () => null);
    }

    private function backoffElapsed(): bool
    {
        if ($this->demotedAt === null) {
            return true;
        }

        return CarbonImmutable::now()->diffInSeconds($this->demotedAt, true) > $this->currentBackoffSeconds();
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function transitionTo(UpstreamDriver $driver, string $type, FeedEventLevel $level, string $message, array $context = []): void
    {
        $this->state = $driver->name();
        $this->since = CarbonImmutable::now();
        $this->record($type, $level, $message, $context);
        $this->publish();
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function record(string $type, FeedEventLevel $level, string $message, array $context): void
    {
        FeedEvent::create([
            'level' => $level,
            'type' => $type,
            'message' => $message,
            'context' => $context,
            'occurred_at' => CarbonImmutable::now(),
        ]);
    }

    private function publish(): void
    {
        $this->redis->hmset(self::STATE_KEY, [
            'driver' => $this->state->value,
            'since' => (string) $this->since->getTimestamp(),
            'reconnects' => (string) $this->reconnects,
            'last_error' => (string) ($this->current->lastError() ?? ''),
        ]);
    }
}
```

There is deliberately **no `failureThreshold` parameter**. Each driver counts its own failures and exposes the verdict through `isHealthy()`; duplicating that count in the manager would put the same rule in two places that could disagree. `tapehouse.driver.failure_threshold` is consumed by `WebSocketDriver`, which is where the counting happens.

- [ ] **Step 5: Run the tests to verify they pass**

Run: `vendor/bin/pest tests/Feature/Upstream/DriverManagerTest.php tests/Feature/Control/FeedControlTest.php`
Expected: PASS, 13 tests.

- [ ] **Step 6: Run the gates and commit**

```bash
vendor/bin/pint
vendor/bin/phpstan analyse --memory-limit=512M
vendor/bin/pest
git add -A
git commit -m "feat: add the driver manager and feed control flag

Demotes an unhealthy primary to the fallback, promotes it back on an
escalating backoff capped at the last configured value, and writes a feed
event plus a redis state update for every transition. The stop flag lives in
redis because the web process and the ingest loop are separate processes.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 6: `QuoteCache`, `TickBuffer` and `FeedMetrics`

**Files:**
- Create: `app/Services/Quotes/QuoteCache.php`, `app/Services/Quotes/TickBuffer.php`, `app/Services/Metrics/FeedMetrics.php`
- Modify: `app/Providers/TapehouseServiceProvider.php`
- Test: `tests/Feature/Quotes/QuoteCacheTest.php`, `tests/Feature/Quotes/TickBufferTest.php`, `tests/Feature/Metrics/FeedMetricsTest.php`

**Interfaces:**
- Consumes: `Quote`, `Symbol`
- Produces:
  - `QuoteCache::put(Quote $q): void`, `get(string $ticker): ?Quote`, `many(array $tickers): array<string, Quote>`
  - `TickBuffer::__construct(ConnectionInterface $db, int $bufferSize, int $flushMs)`, `add(Quote $q, int $symbolId): void`, `flushIfDue(): int`, `flush(): int`, `pending(): int`
  - `FeedMetrics::recordLag(int $ms): void`, `recordTick(): void`, `lagPercentiles(): array{p50:int,p95:int}`, `ticksPerMinute(): int`, `snapshot(): array`

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Quotes/QuoteCacheTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\TickSource;
use App\Services\Quotes\QuoteCache;
use App\Services\Upstream\DTO\Quote;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Redis;

beforeEach(fn () => Redis::connection()->flushdb());

function cachedQuote(string $ticker = 'AAPL', string $price = '228.41'): Quote
{
    $at = CarbonImmutable::parse('2026-08-10 12:00:00.123456');

    return new Quote($ticker, $price, '1.82', '0.80', TickSource::WebSocket, $at, $at->addMilliseconds(40));
}

it('round-trips a quote without narrowing the price', function (): void {
    $cache = new QuoteCache(Redis::connection());
    $cache->put(cachedQuote(price: '12345.12345678'));

    $found = $cache->get('AAPL');

    expect($found)->not->toBeNull()
        ->and($found->price)->toBeString()->toBe('12345.12345678')
        ->and($found->source)->toBe(TickSource::WebSocket);
});

it('preserves sub-second timestamps through the cache', function (): void {
    $cache = new QuoteCache(Redis::connection());
    $cache->put(cachedQuote());

    expect($cache->get('AAPL')->quotedAt->format('u'))->toBe('123456');
});

it('returns null for an unknown ticker', function (): void {
    expect((new QuoteCache(Redis::connection()))->get('NOPE'))->toBeNull();
});

it('fetches many tickers and skips the missing ones', function (): void {
    $cache = new QuoteCache(Redis::connection());
    $cache->put(cachedQuote('AAPL'));
    $cache->put(cachedQuote('MSFT', '417.06'));

    $found = $cache->many(['AAPL', 'MSFT', 'NOPE']);

    expect($found)->toHaveCount(2)
        ->and(array_keys($found))->toBe(['AAPL', 'MSFT']);
});

it('sets a ttl so a dead feed does not serve stale prices forever', function (): void {
    $cache = new QuoteCache(Redis::connection());
    $cache->put(cachedQuote());

    expect(Redis::connection()->ttl('tape:quote:AAPL'))->toBeGreaterThan(0);
});
```

Create `tests/Feature/Quotes/TickBufferTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\TickSource;
use App\Models\Symbol;
use App\Models\Tick;
use App\Services\Quotes\TickBuffer;
use App\Services\Upstream\DTO\Quote;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

function bufferedQuote(string $price = '228.41'): Quote
{
    $at = CarbonImmutable::parse('2026-08-10 12:00:00.123456');

    return new Quote('AAPL', $price, '1.82', '0.80', TickSource::Polling, $at, $at->addMilliseconds(40));
}

it('does not write until the buffer fills', function (): void {
    $symbol = Symbol::factory()->create();
    $buffer = new TickBuffer(DB::connection(), 200, 1000);

    for ($i = 0; $i < 199; $i++) {
        $buffer->add(bufferedQuote(), $symbol->id);
    }

    expect(Tick::count())->toBe(0)
        ->and($buffer->pending())->toBe(199);
});

it('flushes when the buffer reaches its size', function (): void {
    $symbol = Symbol::factory()->create();
    $buffer = new TickBuffer(DB::connection(), 200, 1000);

    for ($i = 0; $i < 200; $i++) {
        $buffer->add(bufferedQuote(), $symbol->id);
    }

    expect(Tick::count())->toBe(200)
        ->and($buffer->pending())->toBe(0);
});

it('inserts the whole batch as a single query', function (): void {
    $symbol = Symbol::factory()->create();
    $buffer = new TickBuffer(DB::connection(), 50, 1000);

    DB::enableQueryLog();
    for ($i = 0; $i < 50; $i++) {
        $buffer->add(bufferedQuote(), $symbol->id);
    }
    $inserts = array_filter(DB::getQueryLog(), fn (array $q): bool => str_starts_with($q['query'], 'insert'));
    DB::disableQueryLog();

    // The point of the buffer. Fifty inserts here would defeat it entirely.
    expect($inserts)->toHaveCount(1);
});

it('flushes on the time threshold even when under-full', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-10 12:00:00'));
    $symbol = Symbol::factory()->create();
    $buffer = new TickBuffer(DB::connection(), 200, 1000);
    $buffer->add(bufferedQuote(), $symbol->id);

    $buffer->flushIfDue();
    expect(Tick::count())->toBe(0);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-10 12:00:01.001'));
    $buffer->flushIfDue();

    expect(Tick::count())->toBe(1);
    CarbonImmutable::setTestNow();
});

it('preserves sub-second timestamps through the raw insert', function (): void {
    $symbol = Symbol::factory()->create();
    $buffer = new TickBuffer(DB::connection(), 1, 1000);

    $buffer->add(bufferedQuote(), $symbol->id);

    // The raw query builder formats DateTimeInterface bindings without a
    // fractional part, so the buffer must hand it pre-formatted strings.
    $row = DB::table('ticks')->first();

    expect((string) $row->quoted_at)->toContain('.123456');
});

it('preserves full price precision', function (): void {
    $symbol = Symbol::factory()->create();
    $buffer = new TickBuffer(DB::connection(), 1, 1000);

    $buffer->add(bufferedQuote('12345.12345678'), $symbol->id);

    expect(Tick::sole()->price)->toBe('12345.12345678');
});

it('flushes whatever remains on demand', function (): void {
    $symbol = Symbol::factory()->create();
    $buffer = new TickBuffer(DB::connection(), 200, 1000);
    $buffer->add(bufferedQuote(), $symbol->id);

    expect($buffer->flush())->toBe(1)
        ->and(Tick::count())->toBe(1);
});

it('is safe to flush when empty', function (): void {
    $buffer = new TickBuffer(DB::connection(), 200, 1000);

    expect($buffer->flush())->toBe(0)
        ->and(Tick::count())->toBe(0);
});
```

Create `tests/Feature/Metrics/FeedMetricsTest.php`:

```php
<?php

declare(strict_types=1);

use App\Services\Metrics\FeedMetrics;
use Illuminate\Support\Facades\Redis;

beforeEach(fn () => Redis::connection()->flushdb());

it('reports zero percentiles with no samples', function (): void {
    expect((new FeedMetrics(Redis::connection()))->lagPercentiles())->toBe(['p50' => 0, 'p95' => 0]);
});

it('computes p50 and p95 over the window', function (): void {
    $m = new FeedMetrics(Redis::connection());

    foreach (range(1, 100) as $ms) {
        $m->recordLag($ms);
    }

    $p = $m->lagPercentiles();

    expect($p['p50'])->toBeGreaterThanOrEqual(49)->toBeLessThanOrEqual(51)
        ->and($p['p95'])->toBeGreaterThanOrEqual(94)->toBeLessThanOrEqual(96);
});

it('trims the lag window to 500 samples', function (): void {
    $m = new FeedMetrics(Redis::connection());

    foreach (range(1, 600) as $ms) {
        $m->recordLag($ms);
    }

    expect(Redis::connection()->llen('tape:metrics:lag'))->toBe(500);
});

it('counts ticks per minute', function (): void {
    $m = new FeedMetrics(Redis::connection());

    $m->recordTick();
    $m->recordTick();
    $m->recordTick();

    expect($m->ticksPerMinute())->toBe(3);
});

it('produces the snapshot the ops panel reads', function (): void {
    $m = new FeedMetrics(Redis::connection());
    $m->recordLag(34);
    $m->recordTick();

    $snapshot = $m->snapshot();

    expect($snapshot)->toHaveKeys(['lag_p50', 'lag_p95', 'ticks_per_minute']);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/Quotes tests/Feature/Metrics`
Expected: FAIL — classes not found.

- [ ] **Step 3: Write `QuoteCache`**

Create `app/Services/Quotes/QuoteCache.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Quotes;

use App\Enums\TickSource;
use App\Services\Upstream\DTO\Quote;
use Carbon\CarbonImmutable;
use Illuminate\Redis\Connections\Connection;

/**
 * The only read path for current price. `GET /api/quotes` reads this and never
 * touches Postgres, so a reconnecting client's snapshot costs one Redis round
 * trip rather than a query against the append-heavy ticks table.
 */
final readonly class QuoteCache
{
    private const TTL_SECONDS = 3600;

    public function __construct(private Connection $redis) {}

    public function put(Quote $quote): void
    {
        $key = $this->key($quote->ticker);

        $this->redis->hmset($key, [
            'ticker' => $quote->ticker,
            'price' => $quote->price,
            'day_change' => (string) $quote->dayChange,
            'day_change_pct' => (string) $quote->dayChangePct,
            'source' => $quote->source->value,
            // Microsecond format: a whole-second timestamp here would make the
            // age column jump and destroy the lag figure on the ops panel.
            'quoted_at' => $quote->quotedAt->format('Y-m-d H:i:s.u'),
            'received_at' => $quote->receivedAt->format('Y-m-d H:i:s.u'),
        ]);

        $this->redis->expire($key, self::TTL_SECONDS);
    }

    public function get(string $ticker): ?Quote
    {
        /** @var array<string, string> $hash */
        $hash = $this->redis->hgetall($this->key($ticker));

        return $hash === [] ? null : $this->hydrate($hash);
    }

    /**
     * @param  list<string>  $tickers
     * @return array<string, Quote>
     */
    public function many(array $tickers): array
    {
        $found = [];

        foreach ($tickers as $ticker) {
            $quote = $this->get($ticker);

            if ($quote instanceof Quote) {
                $found[$ticker] = $quote;
            }
        }

        return $found;
    }

    /**
     * @param  array<string, string>  $hash
     */
    private function hydrate(array $hash): Quote
    {
        return new Quote(
            ticker: $hash['ticker'],
            price: $hash['price'],
            dayChange: $hash['day_change'] === '' ? null : $hash['day_change'],
            dayChangePct: $hash['day_change_pct'] === '' ? null : $hash['day_change_pct'],
            source: TickSource::from($hash['source']),
            quotedAt: CarbonImmutable::parse($hash['quoted_at']),
            receivedAt: CarbonImmutable::parse($hash['received_at']),
        );
    }

    private function key(string $ticker): string
    {
        return 'tape:quote:'.$ticker;
    }
}
```

- [ ] **Step 4: Write `TickBuffer`**

Create `app/Services/Quotes/TickBuffer.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Quotes;

use App\Services\Upstream\DTO\Quote;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * Batched writer for the audit path.
 *
 * At eight symbols the difference between this and an insert per tick is
 * nothing; the write path is shaped for the case where it is eight thousand.
 */
final class TickBuffer
{
    /** @var list<array<string, mixed>> */
    private array $rows = [];

    private ?CarbonImmutable $lastFlushAt = null;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly int $bufferSize,
        private readonly int $flushMs,
    ) {}

    public function add(Quote $quote, int $symbolId): void
    {
        $this->lastFlushAt ??= CarbonImmutable::now();
        $this->rows[] = $quote->toTickRow($symbolId);

        if (count($this->rows) >= $this->bufferSize) {
            $this->flush();
        }
    }

    public function flushIfDue(): int
    {
        if ($this->rows === []) {
            return 0;
        }

        $elapsedMs = $this->lastFlushAt === null
            ? PHP_INT_MAX
            : (int) (CarbonImmutable::now()->getPreciseTimestamp(3) - $this->lastFlushAt->getPreciseTimestamp(3));

        return $elapsedMs >= $this->flushMs ? $this->flush() : 0;
    }

    /**
     * One multi-row insert, never one per tick.
     */
    public function flush(): int
    {
        if ($this->rows === []) {
            return 0;
        }

        $count = count($this->rows);

        $this->db->table('ticks')->insert($this->rows);

        $this->rows = [];
        $this->lastFlushAt = CarbonImmutable::now();

        return $count;
    }

    public function pending(): int
    {
        return count($this->rows);
    }
}
```

- [ ] **Step 5: Write `FeedMetrics`**

Create `app/Services/Metrics/FeedMetrics.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Metrics;

use Carbon\CarbonImmutable;
use Illuminate\Redis\Connections\Connection;

final readonly class FeedMetrics
{
    private const LAG_KEY = 'tape:metrics:lag';

    private const LAG_WINDOW = 500;

    public function __construct(private Connection $redis) {}

    public function recordLag(int $ms): void
    {
        $this->redis->lpush(self::LAG_KEY, (string) $ms);
        $this->redis->ltrim(self::LAG_KEY, 0, self::LAG_WINDOW - 1);
    }

    public function recordTick(): void
    {
        $key = $this->minuteKey();
        $this->redis->incr($key);
        $this->redis->expire($key, 300);
    }

    /**
     * @return array{p50: int, p95: int}
     */
    public function lagPercentiles(): array
    {
        /** @var list<string> $samples */
        $samples = $this->redis->lrange(self::LAG_KEY, 0, -1);

        if ($samples === []) {
            return ['p50' => 0, 'p95' => 0];
        }

        $values = array_map('intval', $samples);
        sort($values);

        return [
            'p50' => $this->percentile($values, 0.50),
            'p95' => $this->percentile($values, 0.95),
        ];
    }

    public function ticksPerMinute(): int
    {
        return (int) ($this->redis->get($this->minuteKey()) ?? 0);
    }

    /**
     * @return array{lag_p50: int, lag_p95: int, ticks_per_minute: int}
     */
    public function snapshot(): array
    {
        $lag = $this->lagPercentiles();

        return [
            'lag_p50' => $lag['p50'],
            'lag_p95' => $lag['p95'],
            'ticks_per_minute' => $this->ticksPerMinute(),
        ];
    }

    /**
     * @param  list<int>  $sorted
     */
    private function percentile(array $sorted, float $q): int
    {
        $index = (int) ceil($q * count($sorted)) - 1;

        return $sorted[max(0, min($index, count($sorted) - 1))];
    }

    private function minuteKey(): string
    {
        return 'tape:metrics:ticks_minute:'.CarbonImmutable::now()->format('YmdHi');
    }
}
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `vendor/bin/pest tests/Feature/Quotes tests/Feature/Metrics`
Expected: PASS, 18 tests.

- [ ] **Step 7: Run the gates and commit**

```bash
vendor/bin/pint
vendor/bin/phpstan analyse --memory-limit=512M
vendor/bin/pest
git add -A
git commit -m "feat: add the quote cache, tick buffer and feed metrics

Redis is the read path and Postgres the audit path: current price never
touches the database. The buffer writes one multi-row insert per batch rather
than one per tick, and hands the query builder pre-formatted microsecond
strings because it truncates DateTimeInterface bindings to whole seconds.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 7: `TapeIngest`, `TapePrune` and the service bindings

**Files:**
- Create: `app/Console/Commands/TapeIngest.php`, `app/Console/Commands/TapePrune.php`
- Modify: `app/Providers/TapehouseServiceProvider.php`, `routes/console.php`
- Test: `tests/Feature/Console/TapeIngestTest.php`, `tests/Feature/Console/TapePruneTest.php`

**Interfaces:**
- Consumes: everything above
- Produces: `php artisan tape:ingest {--symbols=} {--driver=} {--once}` and `php artisan tape:prune`. Plan 3 extends `TapeIngest` to broadcast.

The `--once` flag runs a single supervised pass and exits. It exists so the command is testable at all — a loop that only ever runs forever cannot be asserted against.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Console/TapeIngestTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\FeedEvent;
use App\Models\Symbol;
use App\Models\Tick;
use App\Models\User;
use App\Models\Watchlist;
use App\Services\Quotes\QuoteCache;
use Illuminate\Support\Facades\Redis;

beforeEach(function (): void {
    Redis::connection()->flushdb();
    config()->set('tapehouse.simulator.enabled', true);
    config()->set('tapehouse.simulator.interval_ms', 0);
});

function seedWatchlist(int $count = 3): void
{
    $user = User::factory()->create();
    $watchlist = Watchlist::factory()->for($user)->create();
    $symbols = Symbol::factory()->count($count)->create();
    $watchlist->symbols()->sync(
        $symbols->pluck('id')->mapWithKeys(fn (int $id, int $i): array => [$id => ['position' => $i]])->all()
    );
}

it('resolves its ticker list from the watchlists', function (): void {
    seedWatchlist(3);

    $this->artisan('tape:ingest', ['--once' => true])
        ->assertSuccessful();

    expect(FeedEvent::where('type', 'feed.started')->count())->toBe(1);
});

it('writes ticks to redis and postgres in one pass', function (): void {
    seedWatchlist(3);

    $this->artisan('tape:ingest', ['--once' => true, '--passes' => 20])
        ->assertSuccessful();

    $tickers = Symbol::pluck('ticker')->all();
    $cached = app(QuoteCache::class)->many($tickers);

    expect($cached)->not->toBeEmpty()
        ->and(Tick::count())->toBeGreaterThan(0);
});

it('accepts an explicit symbol list', function (): void {
    Symbol::factory()->create(['ticker' => 'AAPL']);

    $this->artisan('tape:ingest', ['--once' => true, '--symbols' => 'AAPL', '--passes' => 5])
        ->assertSuccessful();

    expect(app(QuoteCache::class)->get('AAPL'))->not->toBeNull();
});

it('records lag and tick metrics', function (): void {
    seedWatchlist(2);

    $this->artisan('tape:ingest', ['--once' => true, '--passes' => 10])->assertSuccessful();

    expect(Redis::connection()->llen('tape:metrics:lag'))->toBeGreaterThan(0);
});

it('flushes the buffer before exiting so no tick is lost', function (): void {
    seedWatchlist(1);

    // Five passes is far below the 200-row buffer threshold. Without a flush
    // on shutdown every one of those ticks would be dropped.
    $this->artisan('tape:ingest', ['--once' => true, '--passes' => 5])->assertSuccessful();

    expect(Tick::count())->toBeGreaterThan(0);
});

it('reports which driver it is running, in plain words', function (): void {
    seedWatchlist(1);

    $this->artisan('tape:ingest', ['--once' => true, '--passes' => 1])
        ->expectsOutputToContain('simulated')
        ->assertSuccessful();
});

it('stops when the control flag is set', function (): void {
    seedWatchlist(1);
    app(\App\Services\Control\FeedControl::class)->stop();

    $this->artisan('tape:ingest', ['--once' => true, '--passes' => 3])->assertSuccessful();

    expect(FeedEvent::where('type', 'feed.stopped')->count())->toBe(1)
        ->and(Tick::count())->toBe(0);
});

it('exits cleanly when the watchlist is empty', function (): void {
    $this->artisan('tape:ingest', ['--once' => true])
        ->expectsOutputToContain('no symbols')
        ->assertSuccessful();
});
```

Create `tests/Feature/Console/TapePruneTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Tick;
use Carbon\CarbonImmutable;

it('deletes ticks older than the retention window and keeps the rest', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-10 12:00:00'));
    config()->set('tapehouse.ticks.retention_hours', 24);

    $old = Tick::factory()->create(['quoted_at' => CarbonImmutable::now()->subHours(25)]);
    $fresh = Tick::factory()->create(['quoted_at' => CarbonImmutable::now()->subHours(1)]);

    $this->artisan('tape:prune')->assertSuccessful();

    expect(Tick::find($old->id))->toBeNull()
        ->and(Tick::find($fresh->id))->not->toBeNull();

    CarbonImmutable::setTestNow();
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/Console`
Expected: FAIL — commands not registered.

- [ ] **Step 3: Complete the service provider bindings**

Replace the `register()` body of `app/Providers/TapehouseServiceProvider.php` so every service is bound with its config scalars. The services stay container-free; this is the only place they meet configuration:

```php
    public function register(): void
    {
        $this->app->singleton(CreditBudget::class, function ($app): CreditBudget {
            return new CreditBudget(
                $this->redis($app),
                (int) $this->config($app)->get('tapehouse.budget.capacity'),
                (int) $this->config($app)->get('tapehouse.budget.refill_per_minute'),
            );
        });

        $this->app->singleton(FeedControl::class, fn ($app): FeedControl => new FeedControl($this->redis($app)));

        $this->app->singleton(QuoteCache::class, fn ($app): QuoteCache => new QuoteCache($this->redis($app)));

        $this->app->singleton(FeedMetrics::class, fn ($app): FeedMetrics => new FeedMetrics($this->redis($app)));

        $this->app->singleton(TickBuffer::class, function ($app): TickBuffer {
            return new TickBuffer(
                $app->make('db')->connection(),
                (int) $this->config($app)->get('tapehouse.ticks.buffer_size'),
                (int) $this->config($app)->get('tapehouse.ticks.flush_ms'),
            );
        });

        $this->app->singleton(TwelveDataClient::class, function ($app): TwelveDataClient {
            return new TwelveDataClient(
                new \GuzzleHttp\Client,
                (string) $this->config($app)->get('tapehouse.api_key'),
                (string) $this->config($app)->get('tapehouse.rest_url'),
            );
        });
    }
```

Add the private helpers `config(Container $app): Config` and `redis(Container $app): Connection` to keep those casts in one place, with the imports they need.

- [ ] **Step 4: Write `TapeIngest`**

Create `app/Console/Commands/TapeIngest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Symbol;
use App\Services\Control\FeedControl;
use App\Services\Metrics\FeedMetrics;
use App\Services\Quotes\QuoteCache;
use App\Services\Quotes\TickBuffer;
use App\Services\Upstream\DriverManager;
use App\Services\Upstream\DTO\Quote;
use App\Services\Upstream\PollingDriver;
use App\Services\Upstream\SimulatedDriver;
use App\Services\Upstream\TwelveDataClient;
use App\Services\Upstream\UpstreamDriver;
use App\Services\Upstream\WebSocketDriver;
use App\Services\Budget\CreditBudget;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Redis\Connections\Connection;
use Ratchet\Client\Connector;
use React\EventLoop\Loop;

final class TapeIngest extends Command
{
    protected $signature = 'tape:ingest
        {--symbols= : Comma-separated tickers, overriding the watchlists}
        {--once : Run a bounded number of synchronous passes instead of the event loop}
        {--passes=1 : How many passes to run under --once}';

    protected $description = 'Ingest live quotes from the upstream feed';

    /** @var array<string, int> ticker => symbol id */
    private array $symbolIds = [];

    public function handle(
        Config $config,
        Connection $redis,
        CreditBudget $budget,
        TwelveDataClient $client,
        FeedControl $control,
        QuoteCache $cache,
        TickBuffer $buffer,
        FeedMetrics $metrics,
    ): int {
        $tickers = $this->resolveTickers();

        if ($tickers === []) {
            $this->warn('no symbols on any watchlist. add one to start the tape.');

            return self::SUCCESS;
        }

        $primary = $this->buildPrimary($config, $client, $budget, $redis, $tickers);
        $fallback = new PollingDriver(
            $client,
            $budget,
            $redis,
            (int) $config->get('tapehouse.poll.batch_size'),
            (int) $config->get('tapehouse.poll.interval_seconds'),
        );

        $manager = new DriverManager(
            $primary,
            $fallback,
            $control,
            $redis,
            (array) $config->get('tapehouse.driver.promotion_backoff'),
        );

        $onQuote = function (Quote $quote) use ($cache, $buffer, $metrics): void {
            $cache->put($quote);
            $metrics->recordLag($quote->lagMs());
            $metrics->recordTick();

            // A quote for a ticker we do not track is not an error — the
            // upstream can echo symbols we unsubscribed from mid-flight.
            if (isset($this->symbolIds[$quote->ticker])) {
                $buffer->add($quote, $this->symbolIds[$quote->ticker]);
            }
        };

        $manager->boot($tickers, $onQuote);

        $this->info(sprintf('tape:ingest running · driver %s · %d symbols', $manager->state()->value, count($tickers)));

        try {
            if ($this->option('once')) {
                $this->runBounded($manager, $buffer, (int) $this->option('passes'));
            } else {
                $this->runLoop($manager, $buffer);
            }
        } finally {
            // Without this every buffered tick below the flush threshold is
            // lost on shutdown.
            $buffer->flush();
            $manager->stopAll();
        }

        return self::SUCCESS;
    }

    private function runBounded(DriverManager $manager, TickBuffer $buffer, int $passes): void
    {
        for ($i = 0; $i < max(1, $passes); $i++) {
            $manager->supervise();
            $manager->current()->tick();
        }

        $buffer->flushIfDue();
    }

    private function runLoop(DriverManager $manager, TickBuffer $buffer): void
    {
        Loop::addPeriodicTimer(0.25, static function () use ($manager): void {
            $manager->supervise();
            $manager->current()->tick();
        });

        Loop::addPeriodicTimer(1.0, static function () use ($buffer): void {
            $buffer->flushIfDue();
        });

        foreach ([SIGTERM, SIGINT] as $signal) {
            Loop::addSignal($signal, static function () use ($buffer, $manager): void {
                $buffer->flush();
                $manager->stopAll();
                Loop::stop();
            });
        }

        Loop::run();
    }

    /**
     * @param  list<string>  $tickers
     */
    private function buildPrimary(
        Config $config,
        TwelveDataClient $client,
        CreditBudget $budget,
        Connection $redis,
        array $tickers,
    ): UpstreamDriver {
        if ((bool) $config->get('tapehouse.simulator.enabled')) {
            $seed = [];
            foreach ($tickers as $ticker) {
                $seed[$ticker] = '100.00';
            }

            return new SimulatedDriver($seed, (int) $config->get('tapehouse.simulator.interval_ms'));
        }

        if ((bool) $config->get('tapehouse.ws_enabled')) {
            return new WebSocketDriver(
                new Connector,
                (string) $config->get('tapehouse.ws_url'),
                (string) $config->get('tapehouse.api_key'),
                90,
                (int) $config->get('tapehouse.driver.failure_threshold'),
            );
        }

        return new PollingDriver(
            $client,
            $budget,
            $redis,
            (int) $config->get('tapehouse.poll.batch_size'),
            (int) $config->get('tapehouse.poll.interval_seconds'),
        );
    }

    /**
     * @return list<string>
     */
    private function resolveTickers(): array
    {
        $option = (string) ($this->option('symbols') ?? '');

        $query = Symbol::query()->where('is_active', true);

        if ($option !== '') {
            $query->whereIn('ticker', array_map('trim', explode(',', $option)));
        } else {
            $query->whereHas('watchlists');
        }

        /** @var \Illuminate\Support\Collection<int, Symbol> $symbols */
        $symbols = $query->get();

        $this->symbolIds = $symbols->pluck('id', 'ticker')->all();

        return array_values($symbols->pluck('ticker')->all());
    }
}
```

Register it by placing the file in `app/Console/Commands` — Laravel 13 auto-discovers commands there.

- [ ] **Step 5: Write `TapePrune`**

Create `app/Console/Commands/TapePrune.php`:

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\ConnectionInterface;

final class TapePrune extends Command
{
    protected $signature = 'tape:prune';

    protected $description = 'Delete ticks older than the retention window';

    public function handle(Config $config, ConnectionInterface $db): int
    {
        $hours = (int) $config->get('tapehouse.ticks.retention_hours');
        $cutoff = CarbonImmutable::now()->subHours($hours);

        $deleted = $db->table('ticks')
            ->where('quoted_at', '<', $cutoff->format('Y-m-d H:i:s.u'))
            ->delete();

        $this->info(sprintf('pruned %d ticks older than %dh', $deleted, $hours));

        return self::SUCCESS;
    }
}
```

Then schedule it hourly. Append to `routes/console.php`:

```php
Schedule::command('tape:prune')->hourly();
```

with `use Illuminate\Support\Facades\Schedule;` at the top.

- [ ] **Step 6: Run the tests to verify they pass**

Run: `vendor/bin/pest tests/Feature/Console`
Expected: PASS, 9 tests.

- [ ] **Step 7: Run the full suite and the gates**

```bash
vendor/bin/pint
vendor/bin/phpstan analyse --memory-limit=512M
vendor/bin/pest
```

- [ ] **Step 8: Verify the loop by hand against the simulator**

```bash
php artisan migrate:fresh --seed
TAPEHOUSE_SIMULATOR_ENABLED=true php artisan tape:ingest --once --passes=200
redis-cli -n 0 keys 'tape:quote:*' | head
redis-cli -n 0 hgetall tape:quote:AAPL
psql -d tapehouse -tAc "SELECT count(*), min(quoted_at), max(quoted_at) FROM ticks;"
psql -d tapehouse -tAc "SELECT received_at - quoted_at AS lag FROM ticks LIMIT 3;"
```

Expected: quote hashes present, ticks written, and the lag column showing sub-second values rather than `00:00:00`.

- [ ] **Step 9: Commit**

```bash
git add -A
git commit -m "feat: add the tape:ingest and tape:prune commands

One ReactPHP loop supervising the driver manager on a 250ms timer and
flushing the tick buffer every second, with SIGTERM flushing before exit so no
buffered tick is lost. The --once/--passes flags run a bounded synchronous
version so the loop's behaviour is testable.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

- [ ] **Step 10: Live upstream verification — DEFERRED UNTIL AN API KEY EXISTS**

`TWELVEDATA_API_KEY` is empty. **Do not attempt this step and do not fail the task for it.** When a key is added:

```bash
TWELVEDATA_WS_ENABLED=false TAPEHOUSE_SIMULATOR_ENABLED=false \
  php artisan tape:ingest --once --passes=3
psql -d tapehouse -tAc "SELECT s.ticker, t.price, t.source FROM ticks t JOIN symbols s ON s.id=t.symbol_id ORDER BY t.id DESC LIMIT 5;"
```

Expected: real prices with `source = polling`. Then with `TWELVEDATA_WS_ENABLED=true`, expect either live websocket quotes or a clean demotion to polling with a `driver.demoted` feed event — both are correct outcomes, and on a trial key the demotion is the expected one.

Report this step as DEFERRED in the task report rather than skipping it silently.

---

## Definition of done

- [ ] `vendor/bin/pest` green, including the whole Plan 1 suite
- [ ] `vendor/bin/phpstan analyse` — `[OK]` at level 6, no baseline
- [ ] `vendor/bin/pint --test` clean
- [ ] `php artisan tape:ingest --once --passes=200` with the simulator writes quote hashes to Redis and ticks to Postgres, with sub-second lag
- [ ] `php artisan tape:prune` deletes only ticks past the retention window
- [ ] No facade used inside `app/Services/**`
- [ ] Live upstream verification recorded as DEFERRED, with the exact commands to run

## What this plan does not build

`QuoteBroadcaster`, the broadcast events, Reverb, channels and the REST API — Plan 3. Webpack, SCSS, Blade and the JavaScript — Plan 4. Production Dockerfile, compose, CI, README — Plan 5.
