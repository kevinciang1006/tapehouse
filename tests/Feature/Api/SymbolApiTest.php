<?php

declare(strict_types=1);

use App\Enums\AssetType;
use App\Models\Symbol;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;

it('rejects a guest', function (): void {
    getJson('/api/symbols')->assertUnauthorized();
});

it('lists active symbols', function (): void {
    Symbol::factory()->count(3)->create();
    Symbol::factory()->create(['is_active' => false]);

    actingAs(User::factory()->create());

    getJson('/api/symbols')->assertOk()->assertJsonCount(3, 'data');
});

it('filters by ticker or name, case-insensitively', function (): void {
    Symbol::factory()->create(['ticker' => 'AAPL', 'name' => 'Apple Inc']);
    Symbol::factory()->create(['ticker' => 'MSFT', 'name' => 'Microsoft Corp']);

    actingAs(User::factory()->create());

    getJson('/api/symbols?q=aapl')->assertOk()->assertJsonCount(1, 'data');
    getJson('/api/symbols?q=microsoft')->assertOk()->assertJsonCount(1, 'data');
});

it('honours the limit and caps it', function (): void {
    Symbol::factory()->count(60)->create();

    actingAs(User::factory()->create());

    getJson('/api/symbols?limit=5')->assertOk()->assertJsonCount(5, 'data');
    // An uncapped limit lets a caller pull the whole table in one request.
    getJson('/api/symbols?limit=9999')->assertOk()->assertJsonCount(50, 'data');
});

it('exposes the display precision the tape needs', function (): void {
    Symbol::factory()->create(['ticker' => 'XAU/USD', 'asset_type' => AssetType::Forex, 'price_decimals' => 2]);

    actingAs(User::factory()->create());

    getJson('/api/symbols?q=XAU')
        ->assertOk()
        ->assertJsonPath('data.0.price_decimals', 2)
        ->assertJsonPath('data.0.asset_type', 'forex');
});
