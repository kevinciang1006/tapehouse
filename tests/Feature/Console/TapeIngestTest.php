<?php

declare(strict_types=1);

use App\Models\FeedEvent;
use App\Models\Symbol;
use App\Models\Tick;
use App\Models\User;
use App\Models\Watchlist;
use App\Services\Control\FeedControl;
use App\Services\Quotes\QuoteCache;
use Illuminate\Support\Facades\Redis;

use function Pest\Laravel\artisan;

beforeEach(function (): void {
    Redis::connection()->flushdb();
    config()->set('tapehouse.simulator.enabled', true);
    config()->set('tapehouse.simulator.interval_ms', 0);
});

function seedWatchlist(int $count = 3): void
{
    $user = User::factory()->create();
    $watchlist = Watchlist::factory()->for($user)->create();
    $symbols = Symbol::factory()->count($count)->create();
    $watchlist->symbols()->sync(
        $symbols->pluck('id')->mapWithKeys(fn (int $id, int $i): array => [$id => ['position' => $i]])->all()
    );
}

it('resolves its ticker list from the watchlists', function (): void {
    seedWatchlist(3);

    artisan('tape:ingest', ['--once' => true])
        ->assertSuccessful();

    expect(FeedEvent::where('type', 'feed.started')->count())->toBe(1);
});

it('writes ticks to redis and postgres in one pass', function (): void {
    seedWatchlist(3);

    artisan('tape:ingest', ['--once' => true, '--passes' => 20])
        ->assertSuccessful();

    $tickers = Symbol::pluck('ticker')->all();
    $cached = app(QuoteCache::class)->many($tickers);

    expect($cached)->not->toBeEmpty()
        ->and(Tick::count())->toBeGreaterThan(0);
});

it('accepts an explicit symbol list', function (): void {
    Symbol::factory()->create(['ticker' => 'AAPL']);

    artisan('tape:ingest', ['--once' => true, '--symbols' => 'AAPL', '--passes' => 5])
        ->assertSuccessful();

    expect(app(QuoteCache::class)->get('AAPL'))->not->toBeNull();
});

it('records lag and tick metrics', function (): void {
    seedWatchlist(2);

    artisan('tape:ingest', ['--once' => true, '--passes' => 10])->assertSuccessful();

    expect(Redis::connection()->llen('tape:metrics:lag'))->toBeGreaterThan(0);
});

it('flushes the buffer before exiting so no tick is lost', function (): void {
    seedWatchlist(1);

    // Five passes is far below the 200-row buffer threshold. Without a flush
    // on shutdown every one of those ticks would be dropped.
    artisan('tape:ingest', ['--once' => true, '--passes' => 5])->assertSuccessful();

    expect(Tick::count())->toBeGreaterThan(0);
});

it('reports which driver it is running, in plain words', function (): void {
    seedWatchlist(1);

    artisan('tape:ingest', ['--once' => true, '--passes' => 1])
        ->expectsOutputToContain('simulated')
        ->assertSuccessful();
});

it('stops when the control flag is set', function (): void {
    seedWatchlist(1);
    app(FeedControl::class)->stop();

    artisan('tape:ingest', ['--once' => true, '--passes' => 3])->assertSuccessful();

    expect(FeedEvent::where('type', 'feed.stopped')->count())->toBe(1)
        ->and(Tick::count())->toBe(0);
});

it('exits cleanly when the watchlist is empty', function (): void {
    artisan('tape:ingest', ['--once' => true])
        ->expectsOutputToContain('no symbols')
        ->assertSuccessful();
});
