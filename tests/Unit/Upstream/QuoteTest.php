<?php

declare(strict_types=1);

use App\Enums\TickSource;
use App\Services\Upstream\DTO\Quote;
use Carbon\CarbonImmutable;

function quote(string $price = '228.41', int $lagMs = 40): Quote
{
    $quotedAt = CarbonImmutable::parse('2026-08-10 12:00:00.000000');

    return new Quote(
        ticker: 'AAPL',
        price: $price,
        dayChange: '1.82',
        dayChangePct: '0.80',
        source: TickSource::WebSocket,
        quotedAt: $quotedAt,
        receivedAt: $quotedAt->addMilliseconds($lagMs),
    );
}

it('keeps the price as a string so precision survives', function (): void {
    $q = quote('12345.12345678');

    expect($q->price)->toBeString()->toBe('12345.12345678');
});

it('computes lag in milliseconds', function (): void {
    expect(quote(lagMs: 40)->lagMs())->toBe(40)
        ->and(quote(lagMs: 0)->lagMs())->toBe(0);
});

it('never reports negative lag when upstream clocks run ahead', function (): void {
    // Twelve Data timestamps come from their clock, not ours. A quoted_at in
    // our future must read as 0 lag, not as a negative that would poison the
    // p50/p95 window.
    expect(quote(lagMs: -250)->lagMs())->toBe(0);
});

it('builds the tick row shape the buffer inserts', function (): void {
    $row = quote()->toTickRow(symbolId: 7);

    expect($row['symbol_id'])->toBe(7)
        ->and($row['price'])->toBe('228.41')
        ->and($row['day_change'])->toBe('1.82')
        ->and($row['source'])->toBe('websocket')
        // Explicit UTC offset on purpose: quoted_at/received_at land in a
        // timestamptz column, and a naive string here would be resolved
        // against the session timezone instead of the instant it names.
        ->and($row['quoted_at'])->toBe('2026-08-10 12:00:00.000000+00:00')
        ->and($row['received_at'])->toBe('2026-08-10 12:00:00.040000+00:00');
});
