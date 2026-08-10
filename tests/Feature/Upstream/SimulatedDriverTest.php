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
