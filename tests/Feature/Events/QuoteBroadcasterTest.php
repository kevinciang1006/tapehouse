<?php

declare(strict_types=1);

use App\Enums\TickSource;
use App\Events\QuotesUpdated;
use App\Services\Quotes\QuoteBroadcaster;
use App\Services\Upstream\DTO\Quote;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;

function broadcastQuote(string $ticker = 'AAPL', string $price = '228.41'): Quote
{
    $at = CarbonImmutable::now();

    return new Quote($ticker, $price, '1.82', '0.80', TickSource::WebSocket, $at, $at);
}

beforeEach(function (): void {
    Event::fake();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-11 12:00:00'));
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('does not broadcast before the coalesce window closes', function (): void {
    $b = new QuoteBroadcaster(app('events'), 250);

    $b->add(broadcastQuote(), 1);
    $b->flushIfDue();

    Event::assertNotDispatched(QuotesUpdated::class);
});

it('broadcasts once the window has elapsed', function (): void {
    $b = new QuoteBroadcaster(app('events'), 250);
    $b->add(broadcastQuote(), 1);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-11 12:00:00.251'));
    $b->flushIfDue();

    Event::assertDispatched(QuotesUpdated::class);
});

it('coalesces many ticks into ONE event', function (): void {
    $b = new QuoteBroadcaster(app('events'), 250);

    // The whole point: a fast-moving symbol must not saturate the socket with
    // one frame per tick.
    for ($i = 0; $i < 50; $i++) {
        $b->add(broadcastQuote(price: (string) (228 + $i)), 1);
    }

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-11 12:00:00.251'));
    $b->flushIfDue();

    Event::assertDispatchedTimes(QuotesUpdated::class, 1);
});

it('keeps only the latest price per ticker in the window', function (): void {
    $b = new QuoteBroadcaster(app('events'), 250);
    $b->add(broadcastQuote('AAPL', '228.41'), 1);
    $b->add(broadcastQuote('AAPL', '229.99'), 1);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-11 12:00:00.251'));
    $b->flush();

    Event::assertDispatched(QuotesUpdated::class, function (QuotesUpdated $e): bool {
        return count($e->quotes) === 1 && $e->quotes[0]['price'] === '229.99';
    });
});

it('separates windows per user so one operator never sees another\'s tape', function (): void {
    $b = new QuoteBroadcaster(app('events'), 250);
    $b->add(broadcastQuote('AAPL'), 1);
    $b->add(broadcastQuote('MSFT'), 2);

    $b->flush();

    Event::assertDispatchedTimes(QuotesUpdated::class, 2);
});

it('flushes whatever is pending on demand', function (): void {
    $b = new QuoteBroadcaster(app('events'), 250);
    $b->add(broadcastQuote(), 1);

    expect($b->flush())->toBe(1);
});

it('is safe to flush when empty', function (): void {
    expect((new QuoteBroadcaster(app('events'), 250))->flush())->toBe(0);

    Event::assertNotDispatched(QuotesUpdated::class);
});

it('broadcasts prices as strings, never floats', function (): void {
    $b = new QuoteBroadcaster(app('events'), 250);
    $b->add(broadcastQuote(price: '12345.12345678'), 1);
    $b->flush();

    Event::assertDispatched(QuotesUpdated::class, function (QuotesUpdated $e): bool {
        return $e->quotes[0]['price'] === '12345.12345678';
    });
});
