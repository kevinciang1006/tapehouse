<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AssetType;
use App\Models\Symbol;
use Illuminate\Database\Seeder;

class SymbolSeeder extends Seeder
{
    /**
     * Tickers use Twelve Data's exact format — slash-separated pairs for forex
     * and crypto, bare tickers for equities. price_decimals is per symbol
     * because precision does not follow asset type: XAU/USD quotes to 2 places
     * while most forex pairs quote to 5.
     *
     * @var list<array{0: string, 1: string, 2: AssetType, 3: ?string, 4: string, 5: int}>
     */
    private const SYMBOLS = [
        ['AAPL', 'Apple Inc', AssetType::Stock, 'NASDAQ', 'USD', 2],
        ['MSFT', 'Microsoft Corp', AssetType::Stock, 'NASDAQ', 'USD', 2],
        ['NVDA', 'NVIDIA Corp', AssetType::Stock, 'NASDAQ', 'USD', 2],
        ['TSLA', 'Tesla Inc', AssetType::Stock, 'NASDAQ', 'USD', 2],
        ['AMZN', 'Amazon.com Inc', AssetType::Stock, 'NASDAQ', 'USD', 2],
        ['GOOGL', 'Alphabet Inc Class A', AssetType::Stock, 'NASDAQ', 'USD', 2],
        ['META', 'Meta Platforms Inc', AssetType::Stock, 'NASDAQ', 'USD', 2],
        ['NFLX', 'Netflix Inc', AssetType::Stock, 'NASDAQ', 'USD', 2],
        ['AMD', 'Advanced Micro Devices Inc', AssetType::Stock, 'NASDAQ', 'USD', 2],
        ['INTC', 'Intel Corp', AssetType::Stock, 'NASDAQ', 'USD', 2],
        ['JPM', 'JPMorgan Chase & Co', AssetType::Stock, 'NYSE', 'USD', 2],
        ['V', 'Visa Inc', AssetType::Stock, 'NYSE', 'USD', 2],
        ['XOM', 'Exxon Mobil Corp', AssetType::Stock, 'NYSE', 'USD', 2],
        ['JNJ', 'Johnson & Johnson', AssetType::Stock, 'NYSE', 'USD', 2],
        ['WMT', 'Walmart Inc', AssetType::Stock, 'NYSE', 'USD', 2],
        ['PG', 'Procter & Gamble Co', AssetType::Stock, 'NYSE', 'USD', 2],
        ['DIS', 'Walt Disney Co', AssetType::Stock, 'NYSE', 'USD', 2],
        ['BA', 'Boeing Co', AssetType::Stock, 'NYSE', 'USD', 2],
        ['SPY', 'SPDR S&P 500 ETF Trust', AssetType::Stock, 'NYSE', 'USD', 2],
        ['QQQ', 'Invesco QQQ Trust', AssetType::Stock, 'NASDAQ', 'USD', 2],

        ['EUR/USD', 'Euro / US Dollar', AssetType::Forex, null, 'USD', 5],
        ['GBP/USD', 'Pound Sterling / US Dollar', AssetType::Forex, null, 'USD', 5],
        ['USD/JPY', 'US Dollar / Japanese Yen', AssetType::Forex, null, 'JPY', 5],
        ['USD/CHF', 'US Dollar / Swiss Franc', AssetType::Forex, null, 'CHF', 5],
        ['AUD/USD', 'Australian Dollar / US Dollar', AssetType::Forex, null, 'USD', 5],
        ['USD/CAD', 'US Dollar / Canadian Dollar', AssetType::Forex, null, 'CAD', 5],
        ['NZD/USD', 'New Zealand Dollar / US Dollar', AssetType::Forex, null, 'USD', 5],
        ['EUR/GBP', 'Euro / Pound Sterling', AssetType::Forex, null, 'GBP', 5],
        ['EUR/JPY', 'Euro / Japanese Yen', AssetType::Forex, null, 'JPY', 5],
        ['GBP/JPY', 'Pound Sterling / Japanese Yen', AssetType::Forex, null, 'JPY', 5],
        ['XAU/USD', 'Gold / US Dollar', AssetType::Forex, null, 'USD', 2],
        ['XAG/USD', 'Silver / US Dollar', AssetType::Forex, null, 'USD', 3],

        ['BTC/USD', 'Bitcoin / US Dollar', AssetType::Crypto, null, 'USD', 2],
        ['ETH/USD', 'Ether / US Dollar', AssetType::Crypto, null, 'USD', 2],
        ['SOL/USD', 'Solana / US Dollar', AssetType::Crypto, null, 'USD', 2],
        ['XRP/USD', 'XRP / US Dollar', AssetType::Crypto, null, 'USD', 4],
        ['ADA/USD', 'Cardano / US Dollar', AssetType::Crypto, null, 'USD', 4],
        ['DOGE/USD', 'Dogecoin / US Dollar', AssetType::Crypto, null, 'USD', 5],
        ['LTC/USD', 'Litecoin / US Dollar', AssetType::Crypto, null, 'USD', 2],
        ['BNB/USD', 'BNB / US Dollar', AssetType::Crypto, null, 'USD', 2],
    ];

    public function run(): void
    {
        foreach (self::SYMBOLS as [$ticker, $name, $assetType, $exchange, $currency, $decimals]) {
            Symbol::updateOrCreate(
                ['ticker' => $ticker],
                [
                    'name' => $name,
                    'asset_type' => $assetType,
                    'exchange' => $exchange,
                    'currency' => $currency,
                    'price_decimals' => $decimals,
                    'is_active' => true,
                ],
            );
        }
    }
}
