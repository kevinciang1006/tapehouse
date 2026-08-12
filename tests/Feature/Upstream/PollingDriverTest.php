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

/**
 * Builds a response echoing whatever symbols were asked for.
 *
 * @return list<Response>
 */
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
    // Capacity 12, not the default 8: this test freezes the clock across all
    // three ticks (no refill), and a correct driver spends tokens 1-per-symbol
    // with no waste — 4 (A-D) + 2 (E,F, the tail-truncated slice a correct,
    // non-wrapping cursor must request) + 4 (A-D again) = 10 tokens needed to
    // observe a full, untruncated third slice. Capacity 8 would starve tick 3
    // to a partial grant purely from budget exhaustion, which is not what this
    // test exists to exercise — that starvation/partial-grant behaviour is
    // covered on its own by the "advances the cursor by the GRANTED count" and
    // "covers every symbol across successive starved passes" tests below.
    $driver = driverWith(new MockHandler(pollingResponses(3)), $r, capacity: 12, batch: 4);
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
