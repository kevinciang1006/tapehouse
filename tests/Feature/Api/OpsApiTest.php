<?php

declare(strict_types=1);

use App\Models\FeedEvent;
use App\Models\User;
use App\Services\Control\FeedControl;
use Illuminate\Support\Facades\Redis;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\json;
use function Pest\Laravel\postJson;

beforeEach(fn () => Redis::connection()->flushdb());

it('rejects a guest on every ops endpoint', function (string $method, string $uri): void {
    json($method, $uri)->assertUnauthorized();
})->with([
    ['GET', '/api/ops/health'],
    ['GET', '/api/feed-events'],
    ['POST', '/api/ops/feed/stop'],
    ['POST', '/api/ops/feed/start'],
]);

it('reports health even before the ingest loop has ever run', function (): void {
    actingAs(User::factory()->create());

    // The web process has no DriverManager; an absent state hash must read as
    // stopped rather than throwing.
    getJson('/api/ops/health')
        ->assertOk()
        ->assertJsonPath('data.driver', 'stopped')
        ->assertJsonStructure(['data' => ['driver', 'seconds_in_state', 'reconnects', 'last_error', 'credits', 'lag', 'ticks_per_minute', 'queue_depth']]);
});

it('reports the driver state the ingest process published', function (): void {
    Redis::connection()->hmset('tape:driver:state', [
        'driver' => 'polling', 'since' => (string) now()->subSeconds(41)->getTimestamp(),
        'reconnects' => '3', 'last_error' => 'ws demoted',
    ]);

    actingAs(User::factory()->create());

    getJson('/api/ops/health')
        ->assertOk()
        ->assertJsonPath('data.driver', 'polling')
        ->assertJsonPath('data.reconnects', 3)
        ->assertJsonPath('data.last_error', 'ws demoted');
});

it('reports the credit budget as spent versus capacity', function (): void {
    actingAs(User::factory()->create());

    getJson('/api/ops/health')
        ->assertOk()
        ->assertJsonPath('data.credits.capacity', 4);
});

it('tails feed events newest first', function (): void {
    FeedEvent::factory()->create(['message' => 'older', 'occurred_at' => now()->subMinute()]);
    FeedEvent::factory()->create(['message' => 'newer', 'occurred_at' => now()]);

    actingAs(User::factory()->create());

    getJson('/api/feed-events?limit=50')
        ->assertOk()
        ->assertJsonPath('data.0.message', 'newer');
});

it('caps the feed event limit', function (): void {
    FeedEvent::factory()->count(60)->create();

    actingAs(User::factory()->create());

    getJson('/api/feed-events?limit=9999')->assertOk()->assertJsonCount(50, 'data');
});

it('stops and starts the feed across processes', function (): void {
    actingAs(User::factory()->create());

    postJson('/api/ops/feed/stop')->assertOk();
    expect(app(FeedControl::class)->isStopped())->toBeTrue();

    postJson('/api/ops/feed/start')->assertOk();
    expect(app(FeedControl::class)->isStopped())->toBeFalse();
});

it('records a feed event when an operator stops the feed', function (): void {
    actingAs(User::factory()->create());

    postJson('/api/ops/feed/stop')->assertOk();

    // The ingest loop also logs its own transition; this row is the audit of
    // who asked for it.
    expect(FeedEvent::where('type', 'feed.stop_requested')->count())->toBe(1);
});
