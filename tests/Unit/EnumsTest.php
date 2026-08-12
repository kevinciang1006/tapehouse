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
