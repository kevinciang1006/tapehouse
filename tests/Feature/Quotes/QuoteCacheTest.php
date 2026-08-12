<?php

declare(strict_types=1);

use App\Enums\TickSource;
use App\Services\Quotes\QuoteCache;
use App\Services\Upstream\DTO\Quote;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Redis;

beforeEach(fn () => Redis::connection()->flushdb());

function cachedQuote(string $ticker = 'AAPL', string $price = '228.41'): Quote
{
    $at = CarbonImmutable::parse('2026-08-10 12:00:00.123456');

    return new Quote($ticker, $price, '1.82', '0.80', TickSource::WebSocket, $at, $at->addMilliseconds(40));
}

it('round-trips a quote without narrowing the price', function (): void {
    $cache = new QuoteCache(Redis::connection());
    $cache->put(cachedQuote(price: '12345.12345678'));

    $found = $cache->get('AAPL');

    expect($found)->not->toBeNull()
        ->and($found->price)->toBeString()->toBe('12345.12345678')
        ->and($found->source)->toBe(TickSource::WebSocket);
});

it('preserves sub-second timestamps through the cache', function (): void {
    $cache = new QuoteCache(Redis::connection());
    $cache->put(cachedQuote());

    expect($cache->get('AAPL')->quotedAt->format('u'))->toBe('123456');
});

it('returns null for an unknown ticker', function (): void {
    expect((new QuoteCache(Redis::connection()))->get('NOPE'))->toBeNull();
});

it('fetches many tickers and skips the missing ones', function (): void {
    $cache = new QuoteCache(Redis::connection());
    $cache->put(cachedQuote('AAPL'));
    $cache->put(cachedQuote('MSFT', '417.06'));

    $found = $cache->many(['AAPL', 'MSFT', 'NOPE']);

    expect($found)->toHaveCount(2)
        ->and(array_keys($found))->toBe(['AAPL', 'MSFT']);
});

it('sets a ttl so a dead feed does not serve stale prices forever', function (): void {
    $cache = new QuoteCache(Redis::connection());
    $cache->put(cachedQuote());

    expect(Redis::connection()->ttl('tape:quote:AAPL'))->toBeGreaterThan(0);
});

it('returns null instead of crashing on a partially-written hash', function (): void {
    // A hash missing a field it needs to hydrate a Quote — e.g. a write that
    // was interrupted mid-hmset — must not surface as an undefined-key
    // warning followed by a TypeError on the quote read path.
    Redis::connection()->hmset('tape:quote:PARTIAL', [
        'ticker' => 'PARTIAL',
        'price' => '10.00',
        // source, quoted_at, received_at deliberately missing.
    ]);

    expect((new QuoteCache(Redis::connection()))->get('PARTIAL'))->toBeNull();
});
