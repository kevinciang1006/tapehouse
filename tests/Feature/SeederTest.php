<?php

declare(strict_types=1);

use App\Enums\AssetType;
use App\Models\Symbol;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

use function Pest\Laravel\seed;

beforeEach(function (): void {
    seed();
});

it('seeds exactly one operator who can authenticate', function (): void {
    $user = User::sole();

    expect($user->email)->toBe('operator@tapehouse.dev')
        ->and(Hash::check('tapehouse', $user->password))->toBeTrue();
});

it('seeds a symbol universe spanning all three asset types', function (): void {
    expect(Symbol::count())->toBe(40)
        ->and(Symbol::where('asset_type', AssetType::Stock)->count())->toBeGreaterThan(0)
        ->and(Symbol::where('asset_type', AssetType::Forex)->count())->toBeGreaterThan(0)
        ->and(Symbol::where('asset_type', AssetType::Crypto)->count())->toBeGreaterThan(0);
});

it('gives XAU/USD two decimals despite being a forex pair', function (): void {
    $gold = Symbol::where('ticker', 'XAU/USD')->sole();

    expect($gold->asset_type)->toBe(AssetType::Forex)
        ->and($gold->price_decimals)->toBe(2);
});

it('seeds the ten symbols the console design renders, in order', function (): void {
    $watchlist = User::sole()->watchlist;

    expect($watchlist->symbols->pluck('ticker')->all())->toBe([
        'AAPL', 'MSFT', 'NVDA', 'SPY', 'EUR/USD',
        'GBP/USD', 'USD/JPY', 'BTC/USD', 'ETH/USD', 'XAU/USD',
    ]);
});

it('is idempotent', function (): void {
    seed();

    expect(User::count())->toBe(1)
        ->and(Symbol::count())->toBe(40);
});
