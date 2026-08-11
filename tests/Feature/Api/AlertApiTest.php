<?php

declare(strict_types=1);

use App\Models\AlertRule;
use App\Models\Symbol;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\json;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;

it('rejects a guest on every alert endpoint', function (string $method, string $uri): void {
    json($method, $uri)->assertUnauthorized();
})->with([
    ['GET', '/api/alert-rules'],
    ['POST', '/api/alert-rules'],
    ['PATCH', '/api/alert-rules/1'],
    ['DELETE', '/api/alert-rules/1'],
    ['GET', '/api/alert-events'],
]);

it('lists only the signed-in operator\'s alert rules', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    AlertRule::factory()->for($user)->create();
    AlertRule::factory()->for($user)->create();
    AlertRule::factory()->for($other)->create();

    actingAs($user);

    getJson('/api/alert-rules')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('creates an alert rule with valid data', function (): void {
    $user = User::factory()->create();
    $symbol = Symbol::factory()->create();

    actingAs($user);

    postJson('/api/alert-rules', [
        'symbol_id' => $symbol->id,
        'metric' => 'price',
        'condition' => 'above',
        'threshold' => '250.00',
        'cooldown_seconds' => 30,
    ])->assertCreated()
        ->assertJsonPath('data.metric', 'price')
        ->assertJsonPath('data.condition', 'above')
        ->assertJsonPath('data.symbol_id', $symbol->id);

    expect(AlertRule::where('user_id', $user->id)->count())->toBe(1);
});

it('rejects an unknown metric', function (): void {
    $user = User::factory()->create();
    $symbol = Symbol::factory()->create();

    actingAs($user);

    postJson('/api/alert-rules', [
        'symbol_id' => $symbol->id,
        'metric' => 'volume',
        'condition' => 'above',
        'threshold' => '250.00',
    ])->assertUnprocessable()->assertJsonValidationErrors('metric');
});

it('rejects an unknown condition', function (): void {
    $user = User::factory()->create();
    $symbol = Symbol::factory()->create();

    actingAs($user);

    postJson('/api/alert-rules', [
        'symbol_id' => $symbol->id,
        'metric' => 'price',
        'condition' => 'sideways',
        'threshold' => '250.00',
    ])->assertUnprocessable()->assertJsonValidationErrors('condition');
});

it('rejects an unknown symbol id', function (): void {
    $user = User::factory()->create();

    actingAs($user);

    postJson('/api/alert-rules', [
        'symbol_id' => 999999,
        'metric' => 'price',
        'condition' => 'above',
        'threshold' => '250.00',
    ])->assertUnprocessable()->assertJsonValidationErrors('symbol_id');
});

it('patches the threshold', function (): void {
    $user = User::factory()->create();
    $rule = AlertRule::factory()->for($user)->create(['threshold' => '230.00000000']);

    actingAs($user);

    patchJson('/api/alert-rules/'.$rule->id, ['threshold' => '260.00'])
        ->assertOk()
        ->assertJsonPath('data.threshold', '260.00000000');

    expect($rule->refresh()->threshold)->toBe('260.00000000');
});

it('patches is_active', function (): void {
    $user = User::factory()->create();
    $rule = AlertRule::factory()->for($user)->create(['is_active' => true]);

    actingAs($user);

    patchJson('/api/alert-rules/'.$rule->id, ['is_active' => false])
        ->assertOk()
        ->assertJsonPath('data.is_active', false);

    expect($rule->refresh()->is_active)->toBeFalse();
});

it('deletes an alert rule', function (): void {
    $user = User::factory()->create();
    $rule = AlertRule::factory()->for($user)->create();

    actingAs($user);

    deleteJson('/api/alert-rules/'.$rule->id)->assertNoContent();

    expect(AlertRule::find($rule->id))->toBeNull();
});

it('forbids patching another operator\'s alert rule', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $rule = AlertRule::factory()->for($other)->create(['threshold' => '230.00000000']);

    actingAs($user);

    patchJson('/api/alert-rules/'.$rule->id, ['threshold' => '999.00'])->assertForbidden();

    expect($rule->refresh()->threshold)->toBe('230.00000000');
});

it('forbids deleting another operator\'s alert rule', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $rule = AlertRule::factory()->for($other)->create();

    actingAs($user);

    deleteJson('/api/alert-rules/'.$rule->id)->assertForbidden();

    expect(AlertRule::find($rule->id))->not->toBeNull();
});
