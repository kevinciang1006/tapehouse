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

it('stores money as numeric, never float or string', function (string $table, string $column): void {
    // Laravel reports the native Postgres type; accept either spelling rather
    // than pinning the test to a driver-reporting detail. What must never
    // appear here is a float type (double precision, real) or a text type.
    expect(Schema::getColumnType($table, $column))->toBeIn(['numeric', 'decimal']);
})->with([
    ['ticks', 'price'],
    ['ticks', 'day_change'],
    ['ticks', 'day_change_pct'],
    ['alert_rules', 'threshold'],
    ['alert_events', 'price'],
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
