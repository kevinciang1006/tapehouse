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
