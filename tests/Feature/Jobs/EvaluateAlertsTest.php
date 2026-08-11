<?php

declare(strict_types=1);

use App\Enums\AlertCondition;
use App\Enums\AlertMetric;
use App\Events\AlertFired;
use App\Jobs\EvaluateAlerts;
use App\Models\AlertEvent;
use App\Models\AlertRule;
use App\Models\Symbol;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    Event::fake();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-11 12:00:00'));
});

afterEach(fn () => CarbonImmutable::setTestNow());

/**
 * @param  array<string, mixed>  $overrides
 */
function priceRule(array $overrides = []): AlertRule
{
    return AlertRule::factory()->create(array_merge([
        'metric' => AlertMetric::Price,
        'condition' => AlertCondition::Above,
        'threshold' => '230.00000000',
        'cooldown_seconds' => 60,
    ], $overrides));
}

it('fires when a price rises above its threshold', function (): void {
    $rule = priceRule();

    EvaluateAlerts::dispatchSync([['symbol_id' => $rule->symbol_id, 'price' => '230.01', 'day_change_pct' => '0.5']]);

    expect(AlertEvent::count())->toBe(1)
        ->and($rule->refresh()->last_fired_at)->not->toBeNull();
    Event::assertDispatched(AlertFired::class);
});

it('does not fire exactly at the threshold', function (): void {
    $rule = priceRule();

    // Strict comparison: a price resting on a round number must not retrigger
    // on every tick until the cooldown absorbs it.
    EvaluateAlerts::dispatchSync([['symbol_id' => $rule->symbol_id, 'price' => '230.00', 'day_change_pct' => null]]);

    expect(AlertEvent::count())->toBe(0);
});

it('fires a below rule', function (): void {
    $rule = priceRule(['condition' => AlertCondition::Below, 'threshold' => '100.00000000']);

    EvaluateAlerts::dispatchSync([['symbol_id' => $rule->symbol_id, 'price' => '99.99', 'day_change_pct' => null]]);

    expect(AlertEvent::count())->toBe(1);
});

it('fires on the change percentage metric', function (): void {
    $rule = priceRule([
        'metric' => AlertMetric::ChangePct,
        'condition' => AlertCondition::Below,
        'threshold' => '-2.00000000',
    ]);

    EvaluateAlerts::dispatchSync([['symbol_id' => $rule->symbol_id, 'price' => '100.00', 'day_change_pct' => '-2.14']]);

    expect(AlertEvent::count())->toBe(1);
});

it('suppresses a second fire inside the cooldown', function (): void {
    $rule = priceRule(['last_fired_at' => CarbonImmutable::now()->subSeconds(30)]);

    EvaluateAlerts::dispatchSync([['symbol_id' => $rule->symbol_id, 'price' => '240.00', 'day_change_pct' => null]]);

    expect(AlertEvent::count())->toBe(0);
});

it('fires again once the cooldown has passed', function (): void {
    $rule = priceRule(['last_fired_at' => CarbonImmutable::now()->subSeconds(61)]);

    EvaluateAlerts::dispatchSync([['symbol_id' => $rule->symbol_id, 'price' => '240.00', 'day_change_pct' => null]]);

    expect(AlertEvent::count())->toBe(1);
});

it('ignores inactive rules', function (): void {
    $rule = priceRule(['is_active' => false]);

    EvaluateAlerts::dispatchSync([['symbol_id' => $rule->symbol_id, 'price' => '240.00', 'day_change_pct' => null]]);

    expect(AlertEvent::count())->toBe(0);
});

it('ignores a change rule when the sample has no percentage', function (): void {
    $rule = priceRule(['metric' => AlertMetric::ChangePct, 'condition' => AlertCondition::Below, 'threshold' => '-2.0']);

    EvaluateAlerts::dispatchSync([['symbol_id' => $rule->symbol_id, 'price' => '100.00', 'day_change_pct' => null]]);

    expect(AlertEvent::count())->toBe(0);
});

it('evaluates rules for many symbols in one batch', function (): void {
    $a = priceRule();
    $b = priceRule(['symbol_id' => Symbol::factory()->create()->id]);

    EvaluateAlerts::dispatchSync([
        ['symbol_id' => $a->symbol_id, 'price' => '240.00', 'day_change_pct' => null],
        ['symbol_id' => $b->symbol_id, 'price' => '240.00', 'day_change_pct' => null],
    ]);

    expect(AlertEvent::count())->toBe(2);
});
