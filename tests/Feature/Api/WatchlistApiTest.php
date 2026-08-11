<?php

declare(strict_types=1);

use App\Models\Symbol;
use App\Models\User;
use App\Models\Watchlist;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

it('rejects a guest', function (): void {
    getJson('/api/watchlist')->assertUnauthorized();
});

it('returns the signed-in operator\'s watchlist in position order', function (): void {
    $user = User::factory()->create();
    $watchlist = Watchlist::factory()->for($user)->create();
    $a = Symbol::factory()->create(['ticker' => 'AAPL']);
    $b = Symbol::factory()->create(['ticker' => 'MSFT']);
    $watchlist->symbols()->sync([$b->id => ['position' => 0], $a->id => ['position' => 1]]);

    actingAs($user);

    getJson('/api/watchlist')
        ->assertOk()
        ->assertJsonPath('data.symbols.0.ticker', 'MSFT')
        ->assertJsonPath('data.symbols.1.ticker', 'AAPL');
});

it('creates a watchlist on first read if the operator has none', function (): void {
    actingAs(User::factory()->create());

    getJson('/api/watchlist')->assertOk()->assertJsonPath('data.symbols', []);
});

it('adds a symbol at the end', function (): void {
    $user = User::factory()->create();
    Watchlist::factory()->for($user)->create();
    $symbol = Symbol::factory()->create();

    actingAs($user);

    postJson('/api/watchlist/symbols', ['symbol_id' => $symbol->id])->assertCreated();

    expect($user->watchlist->symbols)->toHaveCount(1);
});

it('rejects a duplicate symbol', function (): void {
    $user = User::factory()->create();
    $watchlist = Watchlist::factory()->for($user)->create();
    $symbol = Symbol::factory()->create();
    $watchlist->symbols()->sync([$symbol->id => ['position' => 0]]);

    actingAs($user);

    postJson('/api/watchlist/symbols', ['symbol_id' => $symbol->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('symbol_id');
});

it('rejects an unknown symbol', function (): void {
    $user = User::factory()->create();
    Watchlist::factory()->for($user)->create();

    actingAs($user);

    postJson('/api/watchlist/symbols', ['symbol_id' => 999999])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('symbol_id');
});

it('removes a symbol', function (): void {
    $user = User::factory()->create();
    $watchlist = Watchlist::factory()->for($user)->create();
    $symbol = Symbol::factory()->create();
    $watchlist->symbols()->sync([$symbol->id => ['position' => 0]]);

    actingAs($user);

    deleteJson('/api/watchlist/symbols/'.$symbol->id)->assertNoContent();

    expect($user->watchlist->refresh()->symbols)->toHaveCount(0);
});

it('never lets one operator touch another\'s watchlist', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $otherList = Watchlist::factory()->for($other)->create();
    $symbol = Symbol::factory()->create();
    $otherList->symbols()->sync([$symbol->id => ['position' => 0]]);
    Watchlist::factory()->for($user)->create();

    actingAs($user);

    // Removing by symbol id must resolve against the CALLER's watchlist only.
    deleteJson('/api/watchlist/symbols/'.$symbol->id)->assertNoContent();

    expect($otherList->refresh()->symbols)->toHaveCount(1);
});
