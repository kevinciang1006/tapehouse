<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Symbol;
use App\Models\User;
use App\Models\Watchlist;
use Illuminate\Database\Seeder;

class WatchlistSeeder extends Seeder
{
    /**
     * The ten symbols the console design renders, in the order it renders
     * them. Mixed deliberately: 2-decimal equities, 5-decimal forex, and
     * crypto with thousands separators, so the tape's decimal alignment is
     * exercised the moment the app boots.
     *
     * @var list<string>
     */
    private const TICKERS = [
        'AAPL', 'MSFT', 'NVDA', 'SPY', 'EUR/USD',
        'GBP/USD', 'USD/JPY', 'BTC/USD', 'ETH/USD', 'XAU/USD',
    ];

    public function run(): void
    {
        $user = User::where('email', 'operator@tapehouse.dev')->sole();

        $watchlist = Watchlist::updateOrCreate(
            ['user_id' => $user->id],
            ['name' => 'Default'],
        );

        $symbols = Symbol::whereIn('ticker', self::TICKERS)
            ->get()
            ->keyBy('ticker');

        $attach = [];

        foreach (self::TICKERS as $position => $ticker) {
            $attach[$symbols[$ticker]->id] = ['position' => $position];
        }

        $watchlist->symbols()->sync($attach);
    }
}
