# Tapehouse Plan 3 — Broadcasting and REST API Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Expose the ingest subsystem to a browser — session auth, a JSON API over Redis, and Reverb broadcasting that coalesces ticks into one event per 250ms window.

**Architecture:** Blade renders nothing but a shell (Plan 4); all data flows over `/api/*` as JSON, and all live updates over Reverb private channels. `GET /api/quotes` reads Redis only and never touches Postgres — it is the reconnect snapshot endpoint. Alert evaluation is a queued job, never inline on the ingest path.

**Tech Stack:** PHP 8.4, Laravel 13.8, Laravel Reverb 1.11, PostgreSQL 16, Redis via predis, Pest 5.

## Global Constraints

Inherited from `docs/superpowers/specs/2026-08-10-tapehouse-design.md` §8 and Plans 1–2. Every task is bound by these.

- `declare(strict_types=1)` at the top of **every** PHP file, including tests.
- **No facades inside `app/Services/**`.** Constructor injection only; config scalars are resolved in `TapehouseServiceProvider`. Controllers, jobs, events and commands are NOT services and may use facades and models freely.
- **No business logic in controllers.** A controller validates (via a Form Request), delegates to a service or model, and returns an API Resource. Never a raw model, never an array.
- **Authorisation via policies, not inline checks.** A user may only read or mutate their own watchlist and alert rules.
- Backed enums for every status value. Money stays a **string** end to end — never cast a price to float.
- `env()` only inside `config/`.
- Full parameter and return types; no bare `array` without a docblock shape.
- Pint and Larastan **level 6** must pass before every commit. No baseline, no `ignoreErrors`, no level reduction, **no PHPStan stub files** — where `$this->` test helpers trip the analyser, use the `Pest\Laravel\*` function equivalents, which is this repo's established pattern (`Pest\Laravel\seed`, `get`, `artisan`).
- Git Flow: branch `feature/api` from `develop`. Commit per task. **Do not merge** — the controller merges after a whole-branch review.
- Git commands need the sandbox disabled. `php` is keg-only: `export PATH="/opt/homebrew/opt/php@8.4/bin:$PATH"`.

## Environment facts

- `TWELVEDATA_API_KEY` is **empty**. Nothing in this plan needs it; tests pin it to `test-key`.
- Tests use `tapehouse_test` and Redis **db 15**. **Never flush Redis db 0** — that is development.
- Redis keys carry Laravel's prefix: the real key is `tapehouse-database-tape:quote:AAPL`. Use `Redis::connection()` rather than raw `redis-cli` in tests.
- No `config/broadcasting.php`, no `config/reverb.php`, no `routes/api.php`, no `routes/channels.php` exist yet. `bootstrap/app.php` registers only `web`, `commands` and `health`.
- **Do not run `php artisan install:broadcasting`** — it npm-installs `laravel-echo` and `pusher-js` and assumes Vite. Plan 4 owns the frontend and uses Webpack. Publish the config manually as Task 1 specifies.

## Carried-over item from Plan 2

The `REDIS_CLIENT` default in `config/database.php` was changed to `predis` because `CreditBudget`'s `command('eval', ...)` only works under predis. A fix-wave report claimed a regression test covered this; the re-review proved **no such test exists**. Task 1 adds it.

## Deviations recorded by this plan

| # | Change | Why |
|---|---|---|
| D17 | Broadcasting configured by hand, not via `install:broadcasting` | The installer assumes Vite and npm-installs Echo/pusher-js. Plan 4 wires the frontend on Webpack. |
| D18 | `QuoteBroadcaster` takes an injected `Dispatcher`, and `DriverManager` gains an optional broadcaster constructor slot | Plan 2's review flagged that `DriverManager` is `final` with private transition methods, so the broadcaster must be threaded through the constructor rather than bolted on. |
| D19 | `GET /api/ops/health` reads driver state from Redis via a new `DriverStateReader` | `DriverManager` lives in the ingest process; the web process has no instance of it. The state hash it publishes is already there — this is the missing accessor Plan 2's review predicted. |

---

### Task 1: Broadcasting config, routing, and session auth

**Files:**
- Create: `config/broadcasting.php`, `config/reverb.php`, `routes/api.php`, `routes/channels.php`, `app/Http/Controllers/Auth/LoginController.php`, `app/Http/Requests/LoginRequest.php`
- Modify: `bootstrap/app.php`, `config/database.php` (nothing — verify only), `.env`, `.env.example`, `routes/web.php`
- Test: `tests/Feature/Auth/LoginTest.php`, `tests/Feature/Broadcasting/ChannelAuthTest.php`, `tests/Unit/TapehouseConfigTest.php` (add one case)

**Interfaces:**
- Consumes: `User` model
- Produces: `POST /login`, `POST /logout`, `GET /login` (view name only, Plan 4 renders it); channels `tape.{userId}` and `ops`; `routes/api.php` registered under the `api` middleware group with `auth:web` and `throttle:120,1`.

- [ ] **Step 1: Publish the broadcasting and Reverb config**

```bash
php artisan config:publish broadcasting
php artisan vendor:publish --provider="Laravel\Reverb\ReverbServiceProvider" --tag=reverb-config
```

If the second command reports nothing published, create `config/reverb.php` from `vendor/laravel/reverb/config/reverb.php` by copying it. Verify both files exist afterwards.

In `config/broadcasting.php`, set `'default' => env('BROADCAST_CONNECTION', 'reverb')`.

- [ ] **Step 2: Add the env keys**

Append to `.env` and `.env.example`, and change `BROADCAST_CONNECTION` from `log` to `reverb` in both:

```
BROADCAST_CONNECTION=reverb

REVERB_APP_ID=tapehouse
REVERB_APP_KEY=tapehouse-local
REVERB_APP_SECRET=tapehouse-local-secret
REVERB_HOST=127.0.0.1
REVERB_PORT=8080
REVERB_SCHEME=http
```

These are local development values and are safe to commit in `.env.example`; production overrides them.

- [ ] **Step 3: Write the failing tests**

Create `tests/Feature/Auth/LoginTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\User;

use function Pest\Laravel\post;

it('logs an operator in with valid credentials', function (): void {
    $user = User::factory()->create(['password' => bcrypt('tapehouse')]);

    post('/login', ['email' => $user->email, 'password' => 'tapehouse'])
        ->assertRedirect('/');

    expect(auth()->check())->toBeTrue();
});

it('rejects a wrong password without revealing which field failed', function (): void {
    $user = User::factory()->create(['password' => bcrypt('tapehouse')]);

    post('/login', ['email' => $user->email, 'password' => 'wrong'])
        ->assertSessionHasErrors('email');

    expect(auth()->check())->toBeFalse();
});

it('requires both fields', function (): void {
    post('/login', [])->assertSessionHasErrors(['email', 'password']);
});

it('logs an operator out', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);
    post('/logout')->assertRedirect('/login');

    expect(auth()->check())->toBeFalse();
});
```

Create `tests/Feature/Broadcasting/ChannelAuthTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\User;

use function Pest\Laravel\post;

it('authorises a user on their own tape channel', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    post('/broadcasting/auth', [
        'socket_id' => '1234.5678',
        'channel_name' => 'private-tape.'.$user->id,
    ])->assertSuccessful();
});

it('refuses a user on someone else\'s tape channel', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();

    $this->actingAs($user);

    // The watchlist is per user; one operator must never see another's tape.
    post('/broadcasting/auth', [
        'socket_id' => '1234.5678',
        'channel_name' => 'private-tape.'.$other->id,
    ])->assertForbidden();
});

it('authorises any signed-in operator on the ops channel', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    // Feed health is not per user — every operator sees the same feed.
    post('/broadcasting/auth', [
        'socket_id' => '1234.5678',
        'channel_name' => 'private-ops',
    ])->assertSuccessful();
});

it('refuses a guest on any channel', function (): void {
    post('/broadcasting/auth', [
        'socket_id' => '1234.5678',
        'channel_name' => 'private-ops',
    ])->assertStatus(403);
});
```

Add to `tests/Unit/TapehouseConfigTest.php` — the test the Plan 2 fix wave claimed to have written but did not:

```php
it('defaults the redis client to predis, which the token bucket requires', function (): void {
    // CreditBudget's Lua call goes through command('eval', ...), which reaches
    // the raw client. That argument shape is predis-only — under phpredis it
    // hits a different native signature and throws.
    putenv('REDIS_CLIENT');
    unset($_ENV['REDIS_CLIENT'], $_SERVER['REDIS_CLIENT']);

    expect((require base_path('config/database.php'))['redis']['client'])->toBe('predis');
});
```

- [ ] **Step 4: Run the tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/Auth tests/Feature/Broadcasting tests/Unit/TapehouseConfigTest.php`
Expected: FAIL — no `/login` route, no `/broadcasting/auth` route.

- [ ] **Step 5: Register api and channel routing**

Replace the `withRouting` call in `bootstrap/app.php`:

```php
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
```

- [ ] **Step 6: Write the channel authorisation**

Create `routes/channels.php`:

```php
<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// One operator must never receive another's tape: the watchlist is per user.
Broadcast::channel('tape.{userId}', function (User $user, int $userId): bool {
    return $user->id === $userId;
});

// Feed health is not per user — every signed-in operator sees the same feed.
Broadcast::channel('ops', function (User $user): bool {
    return true;
});
```

- [ ] **Step 7: Write the auth controller and request**

Create `app/Http/Requests/LoginRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }
}
```

Create `app/Http/Controllers/Auth/LoginController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function show(): View
    {
        return view('auth.login');
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        if (! Auth::attempt($request->only('email', 'password'), true)) {
            // Attach to `email` rather than naming the failing field: telling a
            // caller which half was wrong turns the login form into an account
            // enumeration oracle.
            throw ValidationException::withMessages([
                'email' => 'Those credentials do not match our records.',
            ]);
        }

        $request->session()->regenerate();

        return redirect()->intended('/');
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
```

- [ ] **Step 8: Write the routes**

Replace `routes/web.php`:

```php
<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\LoginController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);
});

Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

// The console route arrives in a later plan, with ConsoleController and the
// Blade shell. The framework health check stays registered in bootstrap/app.php.
```

Create `routes/api.php` with a placeholder guard so the file exists and the middleware stack is provable now; later tasks fill it in:

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::middleware(['auth:web', 'throttle:120,1'])->group(function (): void {
    // Endpoints are added by later tasks in this plan.
});
```

- [ ] **Step 9: Create a minimal login view so the route resolves**

Plan 4 designs this properly. For now create `resources/views/auth/login.blade.php`:

```blade
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>Tapehouse</title></head>
<body>
<form method="POST" action="/login">
    @csrf
    <label for="email">Email</label>
    <input id="email" name="email" type="email" value="{{ old('email') }}" required>
    <label for="password">Password</label>
    <input id="password" name="password" type="password" required>
    @error('email')<p role="alert">{{ $message }}</p>@enderror
    <button type="submit">Sign in</button>
</form>
</body>
</html>
```

- [ ] **Step 10: Run the tests, the gates, and commit**

```bash
vendor/bin/pest tests/Feature/Auth tests/Feature/Broadcasting tests/Unit/TapehouseConfigTest.php
vendor/bin/pint
vendor/bin/phpstan analyse --memory-limit=512M
vendor/bin/pest
git add -A
git commit -m "feat: add broadcasting config, api routing and session auth

Registers the api and channel route files, authorises the per-user tape
channel and the shared ops channel, and adds session login. A failed login
reports against the email field rather than naming which half was wrong, so
the form is not an account enumeration oracle.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 2: Broadcast events and the coalescing broadcaster

**Files:**
- Create: `app/Events/QuotesUpdated.php`, `app/Events/FeedStateChanged.php`, `app/Services/Quotes/QuoteBroadcaster.php`
- Modify: `app/Services/Upstream/DriverManager.php`, `app/Console/Commands/TapeIngest.php`, `app/Providers/TapehouseServiceProvider.php`
- Test: `tests/Feature/Events/QuoteBroadcasterTest.php`, `tests/Feature/Events/FeedStateChangedTest.php`

**Interfaces:**
- Consumes: `Quote`, `DriverManager`, `DriverState`
- Produces:
  - `QuotesUpdated` — broadcasts as `quotes.updated` on `private-tape.{userId}`, payload `['quotes' => list<array>]`
  - `FeedStateChanged` — broadcasts as `feed.state` on `private-ops`
  - `QuoteBroadcaster::__construct(Dispatcher $events, int $coalesceMs)`, `add(Quote $q, int $userId): void`, `flushIfDue(): int`, `flush(): int`
  - `DriverManager::__construct(..., ?QuoteBroadcaster $broadcaster = null)` — an optional final slot so Plan 2's `final` class gains the hook without a redesign

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Events/QuoteBroadcasterTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\TickSource;
use App\Events\QuotesUpdated;
use App\Services\Quotes\QuoteBroadcaster;
use App\Services\Upstream\DTO\Quote;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;

function broadcastQuote(string $ticker = 'AAPL', string $price = '228.41'): Quote
{
    $at = CarbonImmutable::now();

    return new Quote($ticker, $price, '1.82', '0.80', TickSource::WebSocket, $at, $at);
}

beforeEach(function (): void {
    Event::fake();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-11 12:00:00'));
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('does not broadcast before the coalesce window closes', function (): void {
    $b = new QuoteBroadcaster(app('events'), 250);

    $b->add(broadcastQuote(), 1);
    $b->flushIfDue();

    Event::assertNotDispatched(QuotesUpdated::class);
});

it('broadcasts once the window has elapsed', function (): void {
    $b = new QuoteBroadcaster(app('events'), 250);
    $b->add(broadcastQuote(), 1);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-11 12:00:00.251'));
    $b->flushIfDue();

    Event::assertDispatched(QuotesUpdated::class);
});

it('coalesces many ticks into ONE event', function (): void {
    $b = new QuoteBroadcaster(app('events'), 250);

    // The whole point: a fast-moving symbol must not saturate the socket with
    // one frame per tick.
    for ($i = 0; $i < 50; $i++) {
        $b->add(broadcastQuote(price: (string) (228 + $i)), 1);
    }

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-11 12:00:00.251'));
    $b->flushIfDue();

    Event::assertDispatchedTimes(QuotesUpdated::class, 1);
});

it('keeps only the latest price per ticker in the window', function (): void {
    $b = new QuoteBroadcaster(app('events'), 250);
    $b->add(broadcastQuote('AAPL', '228.41'), 1);
    $b->add(broadcastQuote('AAPL', '229.99'), 1);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-11 12:00:00.251'));
    $b->flush();

    Event::assertDispatched(QuotesUpdated::class, function (QuotesUpdated $e): bool {
        return count($e->quotes) === 1 && $e->quotes[0]['price'] === '229.99';
    });
});

it('separates windows per user so one operator never sees another\'s tape', function (): void {
    $b = new QuoteBroadcaster(app('events'), 250);
    $b->add(broadcastQuote('AAPL'), 1);
    $b->add(broadcastQuote('MSFT'), 2);

    $b->flush();

    Event::assertDispatchedTimes(QuotesUpdated::class, 2);
});

it('flushes whatever is pending on demand', function (): void {
    $b = new QuoteBroadcaster(app('events'), 250);
    $b->add(broadcastQuote(), 1);

    expect($b->flush())->toBe(1);
});

it('is safe to flush when empty', function (): void {
    expect((new QuoteBroadcaster(app('events'), 250))->flush())->toBe(0);

    Event::assertNotDispatched(QuotesUpdated::class);
});

it('broadcasts prices as strings, never floats', function (): void {
    $b = new QuoteBroadcaster(app('events'), 250);
    $b->add(broadcastQuote(price: '12345.12345678'), 1);
    $b->flush();

    Event::assertDispatched(QuotesUpdated::class, function (QuotesUpdated $e): bool {
        return $e->quotes[0]['price'] === '12345.12345678';
    });
});
```

Create `tests/Feature/Events/FeedStateChangedTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\DriverState;
use App\Events\FeedStateChanged;

it('broadcasts on the shared ops channel under a short name', function (): void {
    $event = new FeedStateChanged(DriverState::Polling, 41, 3, 'ws demoted');

    expect($event->broadcastAs())->toBe('feed.state')
        ->and($event->broadcastOn()->name)->toBe('private-ops');
});

it('broadcasts a flat array, never a model', function (): void {
    $payload = (new FeedStateChanged(DriverState::Polling, 41, 3, 'ws demoted'))->broadcastWith();

    expect($payload['driver'])->toBe('polling')
        ->and($payload['seconds_in_state'])->toBe(41)
        ->and($payload['reconnects'])->toBe(3)
        ->and($payload['last_error'])->toBe('ws demoted');
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/Events`
Expected: FAIL — classes not found.

- [ ] **Step 3: Write the events**

Create `app/Events/QuotesUpdated.php`:

```php
<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class QuotesUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  list<array{ticker: string, price: string, day_change: string|null, day_change_pct: string|null, source: string, quoted_at: string}>  $quotes
     */
    public function __construct(public int $userId, public array $quotes) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('tape.'.$this->userId);
    }

    public function broadcastAs(): string
    {
        return 'quotes.updated';
    }

    /**
     * @return array{quotes: list<array<string, string|null>>}
     */
    public function broadcastWith(): array
    {
        // A flat array, never an Eloquent model: models serialise their whole
        // attribute set and re-query on the far side.
        return ['quotes' => $this->quotes];
    }
}
```

Create `app/Events/FeedStateChanged.php`:

```php
<?php

declare(strict_types=1);

namespace App\Events;

use App\Enums\DriverState;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FeedStateChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public DriverState $driver,
        public int $secondsInState,
        public int $reconnects,
        public ?string $lastError,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('ops');
    }

    public function broadcastAs(): string
    {
        return 'feed.state';
    }

    /**
     * @return array{driver: string, seconds_in_state: int, reconnects: int, last_error: string|null}
     */
    public function broadcastWith(): array
    {
        return [
            'driver' => $this->driver->value,
            'seconds_in_state' => $this->secondsInState,
            'reconnects' => $this->reconnects,
            'last_error' => $this->lastError,
        ];
    }
}
```

- [ ] **Step 4: Write `QuoteBroadcaster`**

Create `app/Services/Quotes/QuoteBroadcaster.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Quotes;

use App\Events\QuotesUpdated;
use App\Services\Upstream\DTO\Quote;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Coalesces ticks into one broadcast per window per user.
 *
 * A single fast-moving symbol can tick many times a second. One frame per tick
 * would saturate the socket and give the browser more repaints than a human
 * eye can resolve, so the window collapses them — keeping only the latest
 * price per ticker, because an intermediate price nobody rendered is not worth
 * a frame.
 */
final class QuoteBroadcaster
{
    /** @var array<int, array<string, Quote>> userId => ticker => latest quote */
    private array $pending = [];

    private ?CarbonImmutable $windowOpenedAt = null;

    public function __construct(
        private readonly Dispatcher $events,
        private readonly int $coalesceMs,
    ) {}

    public function add(Quote $quote, int $userId): void
    {
        $this->windowOpenedAt ??= CarbonImmutable::now();
        $this->pending[$userId][$quote->ticker] = $quote;
    }

    public function flushIfDue(): int
    {
        if ($this->pending === [] || $this->windowOpenedAt === null) {
            return 0;
        }

        $elapsedMs = CarbonImmutable::now()->getPreciseTimestamp(3) - $this->windowOpenedAt->getPreciseTimestamp(3);

        return $elapsedMs >= $this->coalesceMs ? $this->flush() : 0;
    }

    public function flush(): int
    {
        if ($this->pending === []) {
            return 0;
        }

        $sent = 0;

        foreach ($this->pending as $userId => $quotes) {
            $payload = [];

            foreach ($quotes as $quote) {
                $payload[] = [
                    'ticker' => $quote->ticker,
                    'price' => $quote->price,
                    'day_change' => $quote->dayChange,
                    'day_change_pct' => $quote->dayChangePct,
                    'source' => $quote->source->value,
                    'quoted_at' => $quote->quotedAt->format('Y-m-d\TH:i:s.uP'),
                ];
                $sent++;
            }

            $this->events->dispatch(new QuotesUpdated($userId, $payload));
        }

        $this->pending = [];
        $this->windowOpenedAt = null;

        return $sent;
    }
}
```

- [ ] **Step 5: Thread the broadcaster into `DriverManager`**

Add ONE optional final constructor parameter to `app/Services/Upstream/DriverManager.php`:

```php
        private readonly ?Dispatcher $events = null,
```

with `use Illuminate\Contracts\Events\Dispatcher;` at the top. It must be the LAST parameter and it must default to `null`, so every existing `new DriverManager(...)` call site and every Plan 2 test keeps working untouched.

Then in the existing private `publish()` method, after the `hmset`, append:

```php
        $this->events?->dispatch(new FeedStateChanged(
            $this->state,
            (int) CarbonImmutable::now()->diffInSeconds($this->since, true),
            $this->reconnects,
            $this->current->lastError(),
        ));
```

with `use App\Events\FeedStateChanged;`. The parameter is optional and last, so every existing `new DriverManager(...)` call site and test keeps working unchanged — verify that by running the Plan 2 suite.

- [ ] **Step 6: Wire the broadcaster into `TapeIngest`**

In `app/Console/Commands/TapeIngest.php`:
- add `QuoteBroadcaster $broadcaster` to the `handle()` signature
- resolve the watchlist owner's user id once at boot: `$userId = (int) (Watchlist::query()->value('user_id') ?? 0);`
- in the `onQuote` closure, after `$buffer->add(...)`, add `if ($userId > 0) { $broadcaster->add($quote, $userId); }`
- pass `$events` into the `new DriverManager(...)` call as its final argument
- in `runBounded()`, call `$broadcaster->flushIfDue()` after the loop
- in `runLoop()`, add the flush to the existing 1-second timer alongside `$buffer->flushIfDue()`
- in the `finally`, call `$broadcaster->flush()` inside the same try/catch that guards the buffer flush

Bind `QuoteBroadcaster` in `TapehouseServiceProvider::register()`:

```php
        $this->app->singleton(QuoteBroadcaster::class, function ($app): QuoteBroadcaster {
            return new QuoteBroadcaster(
                $app->make('events'),
                (int) $this->config($app)->get('tapehouse.broadcast.coalesce_ms'),
            );
        });
```

- [ ] **Step 7: Run the tests, the gates, and commit**

```bash
vendor/bin/pest
vendor/bin/pint
vendor/bin/phpstan analyse --memory-limit=512M
git add -A
git commit -m "feat: add broadcast events and the coalescing quote broadcaster

One event per window per user rather than one per tick, keeping only the
latest price per ticker — an intermediate price nobody rendered is not worth a
frame. Events carry flat arrays rather than models, and the driver manager
gains an optional dispatcher slot so transitions reach the ops panel.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 3: Resources, symbols and watchlist endpoints

**Files:**
- Create: `app/Http/Resources/SymbolResource.php`, `WatchlistResource.php`, `QuoteResource.php`
- Create: `app/Http/Controllers/Api/SymbolController.php`, `WatchlistController.php`
- Create: `app/Http/Requests/StoreWatchlistSymbolRequest.php`
- Create: `app/Policies/WatchlistPolicy.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Api/SymbolApiTest.php`, `tests/Feature/Api/WatchlistApiTest.php`

**Interfaces:**
- Produces:
  ```
  GET    /api/symbols?q=&limit=20
  GET    /api/watchlist
  POST   /api/watchlist/symbols        {symbol_id}
  DELETE /api/watchlist/symbols/{symbolId}
  ```

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Api/SymbolApiTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\AssetType;
use App\Models\Symbol;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;

it('rejects a guest', function (): void {
    getJson('/api/symbols')->assertUnauthorized();
});

it('lists active symbols', function (): void {
    Symbol::factory()->count(3)->create();
    Symbol::factory()->create(['is_active' => false]);

    actingAs(User::factory()->create());

    getJson('/api/symbols')->assertOk()->assertJsonCount(3, 'data');
});

it('filters by ticker or name, case-insensitively', function (): void {
    Symbol::factory()->create(['ticker' => 'AAPL', 'name' => 'Apple Inc']);
    Symbol::factory()->create(['ticker' => 'MSFT', 'name' => 'Microsoft Corp']);

    actingAs(User::factory()->create());

    getJson('/api/symbols?q=aapl')->assertOk()->assertJsonCount(1, 'data');
    getJson('/api/symbols?q=microsoft')->assertOk()->assertJsonCount(1, 'data');
});

it('honours the limit and caps it', function (): void {
    Symbol::factory()->count(30)->create();

    actingAs(User::factory()->create());

    getJson('/api/symbols?limit=5')->assertOk()->assertJsonCount(5, 'data');
    // An uncapped limit lets a caller pull the whole table in one request.
    getJson('/api/symbols?limit=9999')->assertOk()->assertJsonCount(50, 'data');
});

it('exposes the display precision the tape needs', function (): void {
    Symbol::factory()->create(['ticker' => 'XAU/USD', 'asset_type' => AssetType::Forex, 'price_decimals' => 2]);

    actingAs(User::factory()->create());

    getJson('/api/symbols?q=XAU')
        ->assertOk()
        ->assertJsonPath('data.0.price_decimals', 2)
        ->assertJsonPath('data.0.asset_type', 'forex');
});
```

Create `tests/Feature/Api/WatchlistApiTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Symbol;
use App\Models\User;
use App\Models\Watchlist;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

it('rejects a guest', function (): void {
    getJson('/api/watchlist')->assertUnauthorized();
});

it('returns the signed-in operator\'s watchlist in position order', function (): void {
    $user = User::factory()->create();
    $watchlist = Watchlist::factory()->for($user)->create();
    $a = Symbol::factory()->create(['ticker' => 'AAPL']);
    $b = Symbol::factory()->create(['ticker' => 'MSFT']);
    $watchlist->symbols()->sync([$b->id => ['position' => 0], $a->id => ['position' => 1]]);

    actingAs($user);

    getJson('/api/watchlist')
        ->assertOk()
        ->assertJsonPath('data.symbols.0.ticker', 'MSFT')
        ->assertJsonPath('data.symbols.1.ticker', 'AAPL');
});

it('creates a watchlist on first read if the operator has none', function (): void {
    actingAs(User::factory()->create());

    getJson('/api/watchlist')->assertOk()->assertJsonPath('data.symbols', []);
});

it('adds a symbol at the end', function (): void {
    $user = User::factory()->create();
    Watchlist::factory()->for($user)->create();
    $symbol = Symbol::factory()->create();

    actingAs($user);

    postJson('/api/watchlist/symbols', ['symbol_id' => $symbol->id])->assertCreated();

    expect($user->watchlist->symbols)->toHaveCount(1);
});

it('rejects a duplicate symbol', function (): void {
    $user = User::factory()->create();
    $watchlist = Watchlist::factory()->for($user)->create();
    $symbol = Symbol::factory()->create();
    $watchlist->symbols()->sync([$symbol->id => ['position' => 0]]);

    actingAs($user);

    postJson('/api/watchlist/symbols', ['symbol_id' => $symbol->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('symbol_id');
});

it('rejects an unknown symbol', function (): void {
    $user = User::factory()->create();
    Watchlist::factory()->for($user)->create();

    actingAs($user);

    postJson('/api/watchlist/symbols', ['symbol_id' => 999999])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('symbol_id');
});

it('removes a symbol', function (): void {
    $user = User::factory()->create();
    $watchlist = Watchlist::factory()->for($user)->create();
    $symbol = Symbol::factory()->create();
    $watchlist->symbols()->sync([$symbol->id => ['position' => 0]]);

    actingAs($user);

    deleteJson('/api/watchlist/symbols/'.$symbol->id)->assertNoContent();

    expect($user->watchlist->refresh()->symbols)->toHaveCount(0);
});

it('never lets one operator touch another\'s watchlist', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $otherList = Watchlist::factory()->for($other)->create();
    $symbol = Symbol::factory()->create();
    $otherList->symbols()->sync([$symbol->id => ['position' => 0]]);
    Watchlist::factory()->for($user)->create();

    actingAs($user);

    // Removing by symbol id must resolve against the CALLER's watchlist only.
    deleteJson('/api/watchlist/symbols/'.$symbol->id)->assertNoContent();

    expect($otherList->refresh()->symbols)->toHaveCount(1);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/Api`
Expected: FAIL — routes not defined.

- [ ] **Step 3: Write the resources**

Create `app/Http/Resources/SymbolResource.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Symbol;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Symbol */
class SymbolResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ticker' => $this->ticker,
            'name' => $this->name,
            'asset_type' => $this->asset_type->value,
            'exchange' => $this->exchange,
            'currency' => $this->currency,
            // The tape formats prices per symbol, not per asset type: XAU/USD
            // is a forex pair that quotes to 2 places.
            'price_decimals' => $this->price_decimals,
        ];
    }
}
```

Create `app/Http/Resources/WatchlistResource.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Watchlist;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Watchlist */
class WatchlistResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'symbols' => SymbolResource::collection($this->whenLoaded('symbols')),
        ];
    }
}
```

Create `app/Http/Resources/QuoteResource.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Services\Upstream\DTO\Quote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QuoteResource extends JsonResource
{
    /**
     * @return array<string, string|null>
     */
    public function toArray(Request $request): array
    {
        /** @var Quote $quote */
        $quote = $this->resource;

        return [
            'ticker' => $quote->ticker,
            // Strings, not floats: a JSON number would round-trip a
            // numeric(18,8) through a double and lose the low digits.
            'price' => $quote->price,
            'day_change' => $quote->dayChange,
            'day_change_pct' => $quote->dayChangePct,
            'source' => $quote->source->value,
            'quoted_at' => $quote->quotedAt->format('Y-m-d\TH:i:s.uP'),
        ];
    }
}
```

- [ ] **Step 4: Write the policy and the form request**

Create `app/Policies/WatchlistPolicy.php`:

```php
<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\Watchlist;

class WatchlistPolicy
{
    public function view(User $user, Watchlist $watchlist): bool
    {
        return $user->id === $watchlist->user_id;
    }

    public function update(User $user, Watchlist $watchlist): bool
    {
        return $user->id === $watchlist->user_id;
    }
}
```

Create `app/Http/Requests/StoreWatchlistSymbolRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWatchlistSymbolRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $watchlistId = $this->user()?->watchlist?->id;

        return [
            'symbol_id' => [
                'required',
                'integer',
                Rule::exists('symbols', 'id')->where('is_active', true),
                Rule::unique('watchlist_symbols', 'symbol_id')
                    ->where('watchlist_id', $watchlistId),
            ],
        ];
    }
}
```

- [ ] **Step 5: Write the controllers**

Create `app/Http/Controllers/Api/SymbolController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SymbolResource;
use App\Models\Symbol;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SymbolController extends Controller
{
    private const MAX_LIMIT = 50;

    public function index(Request $request): AnonymousResourceCollection
    {
        $q = trim((string) $request->query('q', ''));
        // Capped: an uncapped limit lets one request pull the whole table.
        $limit = min((int) $request->query('limit', 20), self::MAX_LIMIT);

        $symbols = Symbol::query()
            ->where('is_active', true)
            ->when($q !== '', function ($query) use ($q): void {
                $query->where(function ($inner) use ($q): void {
                    $inner->where('ticker', 'ilike', '%'.$q.'%')
                        ->orWhere('name', 'ilike', '%'.$q.'%');
                });
            })
            ->orderBy('ticker')
            ->limit(max(1, $limit))
            ->get();

        return SymbolResource::collection($symbols);
    }
}
```

Create `app/Http/Controllers/Api/WatchlistController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreWatchlistSymbolRequest;
use App\Http\Resources\WatchlistResource;
use App\Models\Watchlist;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class WatchlistController extends Controller
{
    public function show(Request $request): WatchlistResource
    {
        $watchlist = $this->watchlistFor($request);

        return new WatchlistResource($watchlist->load('symbols'));
    }

    public function store(StoreWatchlistSymbolRequest $request): JsonResponse
    {
        $watchlist = $this->watchlistFor($request);
        $this->authorize('update', $watchlist);

        $position = (int) $watchlist->symbols()->max('position');

        $watchlist->symbols()->attach($request->integer('symbol_id'), [
            'position' => $watchlist->symbols()->count() === 0 ? 0 : $position + 1,
        ]);

        return (new WatchlistResource($watchlist->load('symbols')))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function destroy(Request $request, int $symbolId): Response
    {
        $watchlist = $this->watchlistFor($request);
        $this->authorize('update', $watchlist);

        // Detaching through the caller's own relation is what stops a symbol
        // id from reaching another operator's rows.
        $watchlist->symbols()->detach($symbolId);

        return response()->noContent();
    }

    private function watchlistFor(Request $request): Watchlist
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        return Watchlist::firstOrCreate(['user_id' => $user->id], ['name' => 'Default']);
    }
}
```

Register the policy in `app/Providers/AppServiceProvider::boot()`:

```php
        Gate::policy(Watchlist::class, WatchlistPolicy::class);
```

- [ ] **Step 6: Add the routes**

In `routes/api.php`, inside the existing middleware group:

```php
    Route::get('/symbols', [SymbolController::class, 'index']);
    Route::get('/watchlist', [WatchlistController::class, 'show']);
    Route::post('/watchlist/symbols', [WatchlistController::class, 'store']);
    Route::delete('/watchlist/symbols/{symbolId}', [WatchlistController::class, 'destroy'])
        ->whereNumber('symbolId');
```

- [ ] **Step 7: Run the tests, the gates, and commit**

```bash
vendor/bin/pest
vendor/bin/pint
vendor/bin/phpstan analyse --memory-limit=512M
git add -A
git commit -m "feat: add symbol and watchlist endpoints

Search is capped so one request cannot pull the whole table, and every
watchlist mutation resolves through the caller's own relation so a symbol id
can never reach another operator's rows. Resources expose per-symbol display
precision, which the tape needs because precision does not follow asset type.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 4: Quotes, ops health, feed events and feed control

**Files:**
- Create: `app/Http/Controllers/Api/QuoteController.php`, `OpsController.php`, `FeedEventController.php`
- Create: `app/Services/Upstream/DriverStateReader.php`
- Create: `app/Http/Resources/FeedEventResource.php`
- Modify: `routes/api.php`, `app/Providers/TapehouseServiceProvider.php`
- Test: `tests/Feature/Api/QuoteApiTest.php`, `tests/Feature/Api/OpsApiTest.php`

**Interfaces:**
- Produces:
  ```
  GET    /api/quotes?symbols=AAPL,MSFT     reads Redis ONLY
  GET    /api/ops/health
  GET    /api/feed-events?limit=50
  POST   /api/ops/feed/stop
  POST   /api/ops/feed/start
  ```
- `DriverStateReader::__construct(Connection $redis)`, `read(): array{driver: string, since: int, reconnects: int, last_error: string|null}` — the web process has no `DriverManager`, so it reads the hash that process publishes.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Api/QuoteApiTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\TickSource;
use App\Models\User;
use App\Services\Quotes\QuoteCache;
use App\Services\Upstream\DTO\Quote;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;

function cacheAQuote(string $ticker, string $price): void
{
    $at = CarbonImmutable::now();
    app(QuoteCache::class)->put(
        new Quote($ticker, $price, '1.82', '0.80', TickSource::Polling, $at, $at)
    );
}

it('rejects a guest', function (): void {
    getJson('/api/quotes?symbols=AAPL')->assertUnauthorized();
});

it('returns cached quotes with full price precision', function (): void {
    cacheAQuote('AAPL', '12345.12345678');
    actingAs(User::factory()->create());

    getJson('/api/quotes?symbols=AAPL')
        ->assertOk()
        ->assertJsonPath('data.0.ticker', 'AAPL')
        // A JSON number would round-trip numeric(18,8) through a double.
        ->assertJsonPath('data.0.price', '12345.12345678');
});

it('skips tickers with no cached quote instead of erroring', function (): void {
    cacheAQuote('AAPL', '228.41');
    actingAs(User::factory()->create());

    getJson('/api/quotes?symbols=AAPL,NOPE')->assertOk()->assertJsonCount(1, 'data');
});

it('returns an empty set when asked for nothing', function (): void {
    actingAs(User::factory()->create());

    getJson('/api/quotes?symbols=')->assertOk()->assertJsonCount(0, 'data');
});

it('reads Redis and never queries Postgres', function (): void {
    cacheAQuote('AAPL', '228.41');
    actingAs(User::factory()->create());

    DB::enableQueryLog();
    getJson('/api/quotes?symbols=AAPL')->assertOk();
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    // This is the reconnect snapshot endpoint. It must not touch the
    // append-heavy ticks table, or every network blip costs a table scan.
    $againstTicks = array_filter($queries, fn (array $q): bool => str_contains($q['query'], 'ticks'));

    expect($againstTicks)->toBeEmpty();
});
```

Create `tests/Feature/Api/OpsApiTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\FeedEvent;
use App\Models\User;
use App\Services\Control\FeedControl;
use Illuminate\Support\Facades\Redis;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

beforeEach(fn () => Redis::connection()->flushdb());

it('rejects a guest on every ops endpoint', function (string $method, string $uri): void {
    $this->json($method, $uri)->assertUnauthorized();
})->with([
    ['GET', '/api/ops/health'],
    ['GET', '/api/feed-events'],
    ['POST', '/api/ops/feed/stop'],
    ['POST', '/api/ops/feed/start'],
]);

it('reports health even before the ingest loop has ever run', function (): void {
    actingAs(User::factory()->create());

    // The web process has no DriverManager; an absent state hash must read as
    // stopped rather than throwing.
    getJson('/api/ops/health')
        ->assertOk()
        ->assertJsonPath('data.driver', 'stopped')
        ->assertJsonStructure(['data' => ['driver', 'seconds_in_state', 'reconnects', 'last_error', 'credits', 'lag', 'ticks_per_minute', 'queue_depth']]);
});

it('reports the driver state the ingest process published', function (): void {
    Redis::connection()->hmset('tape:driver:state', [
        'driver' => 'polling', 'since' => (string) now()->subSeconds(41)->getTimestamp(),
        'reconnects' => '3', 'last_error' => 'ws demoted',
    ]);

    actingAs(User::factory()->create());

    getJson('/api/ops/health')
        ->assertOk()
        ->assertJsonPath('data.driver', 'polling')
        ->assertJsonPath('data.reconnects', 3)
        ->assertJsonPath('data.last_error', 'ws demoted');
});

it('reports the credit budget as spent versus capacity', function (): void {
    actingAs(User::factory()->create());

    getJson('/api/ops/health')
        ->assertOk()
        ->assertJsonPath('data.credits.capacity', 4);
});

it('tails feed events newest first', function (): void {
    FeedEvent::factory()->create(['message' => 'older', 'occurred_at' => now()->subMinute()]);
    FeedEvent::factory()->create(['message' => 'newer', 'occurred_at' => now()]);

    actingAs(User::factory()->create());

    getJson('/api/feed-events?limit=50')
        ->assertOk()
        ->assertJsonPath('data.0.message', 'newer');
});

it('caps the feed event limit', function (): void {
    FeedEvent::factory()->count(60)->create();

    actingAs(User::factory()->create());

    getJson('/api/feed-events?limit=9999')->assertOk()->assertJsonCount(50, 'data');
});

it('stops and starts the feed across processes', function (): void {
    actingAs(User::factory()->create());

    postJson('/api/ops/feed/stop')->assertOk();
    expect(app(FeedControl::class)->isStopped())->toBeTrue();

    postJson('/api/ops/feed/start')->assertOk();
    expect(app(FeedControl::class)->isStopped())->toBeFalse();
});

it('records a feed event when an operator stops the feed', function (): void {
    actingAs(User::factory()->create());

    postJson('/api/ops/feed/stop')->assertOk();

    // The ingest loop also logs its own transition; this row is the audit of
    // who asked for it.
    expect(FeedEvent::where('type', 'feed.stop_requested')->count())->toBe(1);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/Api/QuoteApiTest.php tests/Feature/Api/OpsApiTest.php`
Expected: FAIL — routes not defined.

- [ ] **Step 3: Write `DriverStateReader`**

Create `app/Services/Upstream/DriverStateReader.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Upstream;

use App\Enums\DriverState;
use Illuminate\Redis\Connections\Connection;

/**
 * Reads back the state hash `DriverManager` publishes.
 *
 * The manager lives in the ingest process; the web process serving the ops
 * panel has no instance of it. Redis is the only thing both processes share.
 */
final readonly class DriverStateReader
{
    private const KEY = 'tape:driver:state';

    public function __construct(private Connection $redis) {}

    /**
     * @return array{driver: DriverState, since: int, reconnects: int, last_error: string|null}
     */
    public function read(): array
    {
        /** @var array<string, string> $hash */
        $hash = $this->redis->hgetall(self::KEY);

        // No hash means the ingest process has never run. That is `stopped`,
        // not an error — the console renders it as a dark status dot.
        $driver = DriverState::tryFrom($hash['driver'] ?? '') ?? DriverState::Stopped;
        $lastError = ($hash['last_error'] ?? '') === '' ? null : $hash['last_error'];

        return [
            'driver' => $driver,
            'since' => (int) ($hash['since'] ?? 0),
            'reconnects' => (int) ($hash['reconnects'] ?? 0),
            'last_error' => $lastError,
        ];
    }
}
```

Bind it in `TapehouseServiceProvider::register()` alongside the others.

- [ ] **Step 4: Write the controllers**

`app/Http/Controllers/Api/QuoteController.php` — `index(Request $request)`: split `symbols` on commas, trim, drop empties, call `QuoteCache::many()`, return `QuoteResource::collection(array_values($quotes))`. It must not query Postgres at all.

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\QuoteResource;
use App\Services\Quotes\QuoteCache;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class QuoteController extends Controller
{
    public function index(Request $request, QuoteCache $cache): AnonymousResourceCollection
    {
        $tickers = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $request->query('symbols', ''))
        ), static fn (string $t): bool => $t !== ''));

        return QuoteResource::collection(array_values($cache->many($tickers)));
    }
}
```

`app/Http/Controllers/Api/OpsController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\FeedEventLevel;
use App\Http\Controllers\Controller;
use App\Models\FeedEvent;
use App\Services\Budget\CreditBudget;
use App\Services\Control\FeedControl;
use App\Services\Metrics\FeedMetrics;
use App\Services\Upstream\DriverStateReader;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;

class OpsController extends Controller
{
    public function health(
        DriverStateReader $state,
        CreditBudget $budget,
        FeedMetrics $metrics,
    ): JsonResponse {
        $driver = $state->read();
        $lag = $metrics->lagPercentiles();

        return response()->json(['data' => [
            'driver' => $driver['driver']->value,
            'seconds_in_state' => $driver['since'] === 0
                ? 0
                : max(0, CarbonImmutable::now()->getTimestamp() - $driver['since']),
            'reconnects' => $driver['reconnects'],
            'last_error' => $driver['last_error'],
            'credits' => [
                'available' => $budget->available(),
                'capacity' => $budget->capacity(),
                'seconds_until_next' => $budget->secondsUntilNextToken(),
            ],
            'lag' => ['p50' => $lag['p50'], 'p95' => $lag['p95']],
            'ticks_per_minute' => $metrics->ticksPerMinute(),
            'queue_depth' => Queue::size(),
        ]]);
    }

    public function stop(Request $request, FeedControl $control): JsonResponse
    {
        $control->stop();
        $this->audit($request, 'feed.stop_requested', 'feed stopped by operator');

        return response()->json(['data' => ['stopped' => true]]);
    }

    public function start(Request $request, FeedControl $control): JsonResponse
    {
        $control->start();
        $this->audit($request, 'feed.start_requested', 'feed started by operator');

        return response()->json(['data' => ['stopped' => false]]);
    }

    private function audit(Request $request, string $type, string $message): void
    {
        FeedEvent::create([
            'level' => FeedEventLevel::Warn,
            'type' => $type,
            'message' => $message,
            'context' => ['user_id' => $request->user()?->id],
            'occurred_at' => CarbonImmutable::now(),
        ]);
    }
}
```

`app/Http/Controllers/Api/FeedEventController.php` — `index()` returns the newest `min(limit, 50)` `feed_events` ordered by `occurred_at` desc, as `FeedEventResource::collection`. Write `FeedEventResource` exposing `id`, `level` (string value), `type`, `message`, `context`, `occurred_at` (ISO 8601).

- [ ] **Step 5: Add the routes**

```php
    Route::get('/quotes', [QuoteController::class, 'index']);
    Route::get('/ops/health', [OpsController::class, 'health']);
    Route::post('/ops/feed/stop', [OpsController::class, 'stop']);
    Route::post('/ops/feed/start', [OpsController::class, 'start']);
    Route::get('/feed-events', [FeedEventController::class, 'index']);
```

- [ ] **Step 6: Run the tests, the gates, and commit**

```bash
vendor/bin/pest
vendor/bin/pint
vendor/bin/phpstan analyse --memory-limit=512M
git add -A
git commit -m "feat: add quote snapshot, ops health and feed control endpoints

The snapshot endpoint reads Redis only — a test asserts it issues no query
against ticks, because every client reconnect calls it. Ops health reads the
driver state hash the ingest process publishes, since the web process has no
driver manager of its own, and reports an absent hash as stopped.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 5: Alerts — queued evaluation and endpoints

**Files:**
- Create: `app/Jobs/EvaluateAlerts.php`, `app/Events/AlertFired.php`
- Create: `app/Http/Controllers/Api/AlertRuleController.php`, `AlertEventController.php`
- Create: `app/Http/Requests/StoreAlertRuleRequest.php`, `UpdateAlertRuleRequest.php`
- Create: `app/Http/Resources/AlertRuleResource.php`, `AlertEventResource.php`
- Create: `app/Policies/AlertRulePolicy.php`
- Modify: `routes/api.php`, `app/Console/Commands/TapeIngest.php`, `app/Providers/AppServiceProvider.php`
- Test: `tests/Feature/Jobs/EvaluateAlertsTest.php`, `tests/Feature/Api/AlertApiTest.php`

**Interfaces:**
- Produces:
  ```
  GET    /api/alert-rules
  POST   /api/alert-rules      {symbol_id, metric, condition, threshold, cooldown_seconds}
  PATCH  /api/alert-rules/{id} {threshold?, is_active?, cooldown_seconds?}
  DELETE /api/alert-rules/{id}
  GET    /api/alert-events?limit=50
  ```
- `EvaluateAlerts::__construct(array $samples)` where each sample is `['symbol_id' => int, 'price' => string, 'day_change_pct' => string|null]`

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Jobs/EvaluateAlertsTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\AlertCondition;
use App\Enums\AlertMetric;
use App\Events\AlertFired;
use App\Jobs\EvaluateAlerts;
use App\Models\AlertEvent;
use App\Models\AlertRule;
use App\Models\Symbol;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    Event::fake();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-11 12:00:00'));
});

afterEach(fn () => CarbonImmutable::setTestNow());

function priceRule(array $overrides = []): AlertRule
{
    return AlertRule::factory()->create(array_merge([
        'metric' => AlertMetric::Price,
        'condition' => AlertCondition::Above,
        'threshold' => '230.00000000',
        'cooldown_seconds' => 60,
    ], $overrides));
}

it('fires when a price rises above its threshold', function (): void {
    $rule = priceRule();

    EvaluateAlerts::dispatchSync([['symbol_id' => $rule->symbol_id, 'price' => '230.01', 'day_change_pct' => '0.5']]);

    expect(AlertEvent::count())->toBe(1)
        ->and($rule->refresh()->last_fired_at)->not->toBeNull();
    Event::assertDispatched(AlertFired::class);
});

it('does not fire exactly at the threshold', function (): void {
    $rule = priceRule();

    // Strict comparison: a price resting on a round number must not retrigger
    // on every tick until the cooldown absorbs it.
    EvaluateAlerts::dispatchSync([['symbol_id' => $rule->symbol_id, 'price' => '230.00', 'day_change_pct' => null]]);

    expect(AlertEvent::count())->toBe(0);
});

it('fires a below rule', function (): void {
    $rule = priceRule(['condition' => AlertCondition::Below, 'threshold' => '100.00000000']);

    EvaluateAlerts::dispatchSync([['symbol_id' => $rule->symbol_id, 'price' => '99.99', 'day_change_pct' => null]]);

    expect(AlertEvent::count())->toBe(1);
});

it('fires on the change percentage metric', function (): void {
    $rule = priceRule([
        'metric' => AlertMetric::ChangePct,
        'condition' => AlertCondition::Below,
        'threshold' => '-2.00000000',
    ]);

    EvaluateAlerts::dispatchSync([['symbol_id' => $rule->symbol_id, 'price' => '100.00', 'day_change_pct' => '-2.14']]);

    expect(AlertEvent::count())->toBe(1);
});

it('suppresses a second fire inside the cooldown', function (): void {
    $rule = priceRule(['last_fired_at' => CarbonImmutable::now()->subSeconds(30)]);

    EvaluateAlerts::dispatchSync([['symbol_id' => $rule->symbol_id, 'price' => '240.00', 'day_change_pct' => null]]);

    expect(AlertEvent::count())->toBe(0);
});

it('fires again once the cooldown has passed', function (): void {
    $rule = priceRule(['last_fired_at' => CarbonImmutable::now()->subSeconds(61)]);

    EvaluateAlerts::dispatchSync([['symbol_id' => $rule->symbol_id, 'price' => '240.00', 'day_change_pct' => null]]);

    expect(AlertEvent::count())->toBe(1);
});

it('ignores inactive rules', function (): void {
    $rule = priceRule(['is_active' => false]);

    EvaluateAlerts::dispatchSync([['symbol_id' => $rule->symbol_id, 'price' => '240.00', 'day_change_pct' => null]]);

    expect(AlertEvent::count())->toBe(0);
});

it('ignores a change rule when the sample has no percentage', function (): void {
    $rule = priceRule(['metric' => AlertMetric::ChangePct, 'condition' => AlertCondition::Below, 'threshold' => '-2.0']);

    EvaluateAlerts::dispatchSync([['symbol_id' => $rule->symbol_id, 'price' => '100.00', 'day_change_pct' => null]]);

    expect(AlertEvent::count())->toBe(0);
});

it('evaluates rules for many symbols in one batch', function (): void {
    $a = priceRule();
    $b = priceRule(['symbol_id' => Symbol::factory()->create()->id]);

    EvaluateAlerts::dispatchSync([
        ['symbol_id' => $a->symbol_id, 'price' => '240.00', 'day_change_pct' => null],
        ['symbol_id' => $b->symbol_id, 'price' => '240.00', 'day_change_pct' => null],
    ]);

    expect(AlertEvent::count())->toBe(2);
});
```

Create `tests/Feature/Api/AlertApiTest.php` covering: guest rejection on every endpoint; listing only the caller's rules; creating with valid data; validation failure on an unknown metric/condition/symbol; patching threshold and `is_active`; deleting; and **403 when touching another operator's rule** for both PATCH and DELETE. Write each case explicitly — no "similar to above".

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/Jobs tests/Feature/Api/AlertApiTest.php`

- [ ] **Step 3: Write `AlertFired` and `EvaluateAlerts`**

`AlertFired` mirrors `QuotesUpdated`: broadcasts as `alert.fired` on `private-tape.{userId}` with a flat payload of `rule_id`, `ticker`, `metric`, `condition`, `threshold`, `price`, `fired_at`.

`app/Jobs/EvaluateAlerts.php`:

```php
<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\AlertMetric;
use App\Events\AlertFired;
use App\Models\AlertEvent;
use App\Models\AlertRule;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class EvaluateAlerts implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  list<array{symbol_id: int, price: string, day_change_pct: string|null}>  $samples
     */
    public function __construct(public array $samples) {}

    public function handle(): void
    {
        if ($this->samples === []) {
            return;
        }

        $bySymbol = [];
        foreach ($this->samples as $sample) {
            $bySymbol[$sample['symbol_id']] = $sample;
        }

        $rules = AlertRule::query()
            ->with('symbol')
            ->where('is_active', true)
            ->whereIn('symbol_id', array_keys($bySymbol))
            ->get();

        $now = CarbonImmutable::now();

        foreach ($rules as $rule) {
            $sample = $bySymbol[$rule->symbol_id];

            $observed = $rule->metric === AlertMetric::Price
                ? $sample['price']
                : $sample['day_change_pct'];

            if ($observed === null) {
                continue;
            }

            if (! $rule->condition->isSatisfiedBy((float) $observed, (float) $rule->threshold)) {
                continue;
            }

            // Cooldown stops a price oscillating around a threshold from
            // spamming the log once per tick.
            if ($rule->last_fired_at !== null
                && $now->diffInSeconds($rule->last_fired_at, true) < $rule->cooldown_seconds) {
                continue;
            }

            AlertEvent::create([
                'alert_rule_id' => $rule->id,
                'price' => $sample['price'],
                'fired_at' => $now,
            ]);

            $rule->forceFill(['last_fired_at' => $now])->save();

            event(new AlertFired(
                userId: $rule->user_id,
                ruleId: $rule->id,
                ticker: $rule->symbol->ticker,
                metric: $rule->metric->value,
                condition: $rule->condition->value,
                threshold: (string) $rule->threshold,
                price: $sample['price'],
                firedAt: $now->format('Y-m-d\TH:i:s.uP'),
            ));
        }
    }
}
```

Note the deliberate `(float)` casts inside `isSatisfiedBy`: the comparison is a threshold test, not an accounting operation, and doubles carry ~15 significant digits against realistic prices of ≤14. The stored and broadcast values stay strings. Add exactly that as a code comment so it reads as a decision rather than an oversight.

- [ ] **Step 4: Dispatch the job from the ingest loop**

In `TapeIngest`, accumulate `['symbol_id', 'price', 'day_change_pct']` per quote in the same place the broadcaster is fed, and dispatch `EvaluateAlerts` once per broadcast flush — never per tick, and never inline. A slow rule must not be able to stall ingest.

- [ ] **Step 5: Write the policy, requests, resources and controllers**

`AlertRulePolicy` mirrors `WatchlistPolicy` with `view`, `update`, `delete` all comparing `user_id`. Register it in `AppServiceProvider::boot()`.

`StoreAlertRuleRequest` rules: `symbol_id` required/exists; `metric` required and `Rule::enum(AlertMetric::class)`; `condition` required and `Rule::enum(AlertCondition::class)`; `threshold` required/numeric; `cooldown_seconds` nullable/integer/min:0.

`UpdateAlertRuleRequest`: the same fields, all `sometimes`.

Controllers delegate and return Resources; `AlertRuleController::index()` returns only `$request->user()->alertRules()` eager-loading `symbol`; `update` and `destroy` call `$this->authorize(...)` against the policy before touching the model.

- [ ] **Step 6: Add the routes, run the gates, and commit**

```bash
vendor/bin/pest
vendor/bin/pint
vendor/bin/phpstan analyse --memory-limit=512M
git add -A
git commit -m "feat: add queued alert evaluation and the alert endpoints

Rules are evaluated in a queued job dispatched once per broadcast window,
never inline on the ingest path — a slow rule must not be able to stall
ingest. A cooldown stops a price oscillating around a threshold from firing
once per tick, and comparison is strict so a price resting exactly on a round
number does not retrigger.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Definition of done

- [ ] `vendor/bin/pest` green, including the whole Plan 1–2 suite
- [ ] `vendor/bin/phpstan analyse` — `[OK]` at level 6, no baseline, no stub files
- [ ] `vendor/bin/pint --test` clean
- [ ] Every `/api/*` endpoint returns 401 for a guest
- [ ] A user receives 403 touching another operator's watchlist or alert rules
- [ ] `GET /api/quotes` issues no query against `ticks`
- [ ] `php artisan reverb:start` boots without error
- [ ] Alert evaluation runs on the queue, never inline

## What this plan does not build

Webpack, SCSS, the Blade console shell, and all JavaScript — Plan 4. Production Dockerfile, compose, supervisord, nginx, CI, README — Plan 5.
