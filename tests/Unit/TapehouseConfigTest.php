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

it('escalates the promotion backoff and caps it', function (): void {
    $backoff = config('tapehouse.driver.promotion_backoff');

    expect($backoff)->toBe([60, 120, 300]);
});
