<?php

declare(strict_types=1);

use App\Enums\AlertCondition;
use App\Enums\AlertMetric;
use App\Enums\AssetType;
use App\Enums\FeedEventLevel;
use App\Enums\TickSource;
use App\Models\AlertEvent;
use App\Models\AlertRule;
use App\Models\FeedEvent;
use App\Models\Symbol;
use App\Models\Tick;
use App\Models\User;
use App\Models\Watchlist;

it('casts the symbol asset type to an enum', function (): void {
    $symbol = Symbol::factory()->create(['asset_type' => AssetType::Forex]);

    expect($symbol->refresh()->asset_type)->toBe(AssetType::Forex);
});

it('links a user to one watchlist of many symbols', function (): void {
    $user = User::factory()->create();
    $watchlist = Watchlist::factory()->for($user)->create();
    $symbols = Symbol::factory()->count(3)->create();

    $watchlist->symbols()->attach(
        $symbols->pluck('id')->mapWithKeys(fn (int $id, int $i): array => [$id => ['position' => $i]])->all()
    );

    expect($user->watchlist->is($watchlist))->toBeTrue()
        ->and($watchlist->symbols)->toHaveCount(3)
        ->and($watchlist->symbols->first()->pivot->position)->toBe(0);
});

it('casts tick source and keeps ticks timestamp-free', function (): void {
    $tick = Tick::factory()->create(['source' => TickSource::Polling]);

    expect($tick->refresh()->source)->toBe(TickSource::Polling)
        ->and((new Tick)->usesTimestamps())->toBeFalse();
});

it('casts feed event level and decodes jsonb context', function (): void {
    $event = FeedEvent::factory()->create([
        'level' => FeedEventLevel::Warn,
        'context' => ['from' => 'websocket', 'to' => 'polling'],
    ]);

    // Assert key by key, never `toBe()` on the whole array. PostgreSQL's jsonb
    // stores object keys sorted by length then bytewise, so it hands back
    // ['to' => ..., 'from' => ...] — and `toBe()` is `===`, which is
    // order-sensitive for arrays. The reordering is jsonb working correctly,
    // not a bug to design around: do not downgrade the column to `json` to
    // make a whole-array comparison pass.
    expect($event->refresh()->level)->toBe(FeedEventLevel::Warn)
        ->and($event->context)->toHaveCount(2)
        ->and($event->context['from'])->toBe('websocket')
        ->and($event->context['to'])->toBe('polling');
});

it('casts alert rule metric and condition', function (): void {
    $rule = AlertRule::factory()->create([
        'metric' => AlertMetric::ChangePct,
        'condition' => AlertCondition::Below,
    ]);

    expect($rule->refresh()->metric)->toBe(AlertMetric::ChangePct)
        ->and($rule->condition)->toBe(AlertCondition::Below);
});

it('links alert events back to their rule', function (): void {
    $event = AlertEvent::factory()->create();

    expect($event->rule)->toBeInstanceOf(AlertRule::class)
        ->and($event->rule->events->first()->is($event))->toBeTrue();
});

it('cascades deletes from symbol to tick', function (): void {
    $tick = Tick::factory()->create();

    $tick->symbol->delete();

    expect(Tick::find($tick->id))->toBeNull();
});
