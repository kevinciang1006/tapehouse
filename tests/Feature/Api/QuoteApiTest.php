<?php

declare(strict_types=1);

use App\Enums\TickSource;
use App\Models\User;
use App\Services\Quotes\QuoteCache;
use App\Services\Upstream\DTO\Quote;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;

function cacheAQuote(string $ticker, string $price): void
{
    $at = CarbonImmutable::now();
    app(QuoteCache::class)->put(
        new Quote($ticker, $price, '1.82', '0.80', TickSource::Polling, $at, $at)
    );
}

it('rejects a guest', function (): void {
    getJson('/api/quotes?symbols=AAPL')->assertUnauthorized();
});

it('returns cached quotes with full price precision', function (): void {
    cacheAQuote('AAPL', '12345.12345678');
    actingAs(User::factory()->create());

    getJson('/api/quotes?symbols=AAPL')
        ->assertOk()
        ->assertJsonPath('data.0.ticker', 'AAPL')
        // A JSON number would round-trip numeric(18,8) through a double.
        ->assertJsonPath('data.0.price', '12345.12345678');
});

it('skips tickers with no cached quote instead of erroring', function (): void {
    cacheAQuote('AAPL', '228.41');
    actingAs(User::factory()->create());

    getJson('/api/quotes?symbols=AAPL,NOPE')->assertOk()->assertJsonCount(1, 'data');
});

it('returns an empty set when asked for nothing', function (): void {
    actingAs(User::factory()->create());

    getJson('/api/quotes?symbols=')->assertOk()->assertJsonCount(0, 'data');
});

it('caps the ticker list', function (): void {
    $tickers = [];

    for ($i = 0; $i < 55; $i++) {
        $ticker = 'T'.$i;
        $tickers[] = $ticker;
        cacheAQuote($ticker, '1.00');
    }

    actingAs(User::factory()->create());

    // The hottest endpoint in the app, hit on every reconnect — an unbounded
    // ticker list would be an unbounded cache read on every request.
    getJson('/api/quotes?symbols='.implode(',', $tickers))
        ->assertOk()
        ->assertJsonCount(50, 'data');
});

it('reads Redis and never queries Postgres', function (): void {
    cacheAQuote('AAPL', '228.41');
    actingAs(User::factory()->create());

    DB::enableQueryLog();
    getJson('/api/quotes?symbols=AAPL')->assertOk();
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    // This is the reconnect snapshot endpoint. It must not touch the
    // append-heavy ticks table, or every network blip costs a table scan.
    $againstTicks = array_filter($queries, fn (array $q): bool => str_contains($q['query'], 'ticks'));

    expect($againstTicks)->toBeEmpty();
});
