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

it('defaults the credit budget to half the twelve data trial allowance, so the burst cannot double it', function (): void {
    // Capacity == refill would let a full bucket grant capacity + refill (16)
    // inside a single rolling minute against an 8-credit allowance. Capacity
    // must stay strictly below refill so the worst case is bounded closer to
    // it, while refill_per_minute keeps steady-state throughput at 8/min.
    expect(config('tapehouse.budget.capacity'))->toBe(4)
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

it('defaults the redis client to predis, which the token bucket requires', function (): void {
    // CreditBudget's Lua call goes through command('eval', ...), which reaches
    // the raw client. That argument shape is predis-only — under phpredis it
    // hits a different native signature and throws.
    putenv('REDIS_CLIENT');
    unset($_ENV['REDIS_CLIENT'], $_SERVER['REDIS_CLIENT']);

    expect((require base_path('config/database.php'))['redis']['client'])->toBe('predis');
});
