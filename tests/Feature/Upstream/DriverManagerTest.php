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
