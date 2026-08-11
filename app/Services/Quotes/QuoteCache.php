<?php

declare(strict_types=1);

namespace App\Services\Quotes;

use App\Enums\TickSource;
use App\Services\Upstream\DTO\Quote;
use Carbon\CarbonImmutable;
use Illuminate\Redis\Connections\Connection;

/**
 * The only read path for current price. `GET /api/quotes` reads this and never
 * touches Postgres, so a reconnecting client's snapshot costs one Redis round
 * trip rather than a query against the append-heavy ticks table.
 */
final readonly class QuoteCache
{
    private const TTL_SECONDS = 3600;

    public function __construct(private Connection $redis) {}

    public function put(Quote $quote): void
    {
        $key = $this->key($quote->ticker);

        $this->redis->hmset($key, [
            'ticker' => $quote->ticker,
            'price' => $quote->price,
            'day_change' => (string) $quote->dayChange,
            'day_change_pct' => (string) $quote->dayChangePct,
            'source' => $quote->source->value,
            // Microsecond format: a whole-second timestamp here would make the
            // age column jump and destroy the lag figure on the ops panel.
            'quoted_at' => $quote->quotedAt->format('Y-m-d H:i:s.u'),
            'received_at' => $quote->receivedAt->format('Y-m-d H:i:s.u'),
        ]);

        $this->redis->expire($key, self::TTL_SECONDS);
    }

    public function get(string $ticker): ?Quote
    {
        /** @var array<string, string> $hash */
        $hash = $this->redis->hgetall($this->key($ticker));

        if ($hash === [] || $this->missingRequiredFields($hash)) {
            return null;
        }

        return $this->hydrate($hash);
    }

    /**
     * @param  array<string, string>  $hash
     */
    private function missingRequiredFields(array $hash): bool
    {
        foreach (['ticker', 'price', 'source', 'quoted_at', 'received_at'] as $field) {
            if (! array_key_exists($field, $hash)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $tickers
     * @return array<string, Quote>
     */
    public function many(array $tickers): array
    {
        $found = [];

        foreach ($tickers as $ticker) {
            $quote = $this->get($ticker);

            if ($quote instanceof Quote) {
                $found[$ticker] = $quote;
            }
        }

        return $found;
    }

    /**
     * @param  array<string, string>  $hash
     */
    private function hydrate(array $hash): Quote
    {
        return new Quote(
            ticker: $hash['ticker'],
            price: $hash['price'],
            dayChange: $hash['day_change'] === '' ? null : $hash['day_change'],
            dayChangePct: $hash['day_change_pct'] === '' ? null : $hash['day_change_pct'],
            source: TickSource::from($hash['source']),
            quotedAt: CarbonImmutable::parse($hash['quoted_at']),
            receivedAt: CarbonImmutable::parse($hash['received_at']),
        );
    }

    private function key(string $ticker): string
    {
        return 'tape:quote:'.$ticker;
    }
}
