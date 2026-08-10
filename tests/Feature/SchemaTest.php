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

it('stores money as numeric at full precision, never float or string', function (string $table, string $column, string $definition): void {
    expect(Schema::getColumnType($table, $column, true))->toBe($definition);
})->with([
    ['ticks', 'price', 'numeric(18,8)'],
    ['ticks', 'day_change', 'numeric(18,8)'],
    ['ticks', 'day_change_pct', 'numeric(9,4)'],
    ['alert_rules', 'threshold', 'numeric(18,8)'],
    ['alert_events', 'price', 'numeric(18,8)'],
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
