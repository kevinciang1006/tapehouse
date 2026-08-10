<?php

declare(strict_types=1);

namespace App\Services\Upstream\DTO;

use App\Enums\TickSource;
use Carbon\CarbonImmutable;

final readonly class Quote
{
    public function __construct(
        public string $ticker,
        public string $price,
        public ?string $dayChange,
        public ?string $dayChangePct,
        public TickSource $source,
        public CarbonImmutable $quotedAt,
        public CarbonImmutable $receivedAt,
    ) {}

    /**
     * Milliseconds between the upstream's quote timestamp and our receipt.
     * Clamped at zero: the upstream stamps with its own clock, and a skew that
     * puts quotedAt in our future must not poison the p50/p95 lag window.
     */
    public function lagMs(): int
    {
        $ms = (int) round(($this->receivedAt->getPreciseTimestamp(3) - $this->quotedAt->getPreciseTimestamp(3)));

        return max(0, $ms);
    }

    /**
     * @return array{
     *     symbol_id: int, price: string, day_change: string|null,
     *     day_change_pct: string|null, source: string,
     *     quoted_at: string, received_at: string
     * }
     */
    public function toTickRow(int $symbolId): array
    {
        return [
            'symbol_id' => $symbolId,
            'price' => $this->price,
            'day_change' => $this->dayChange,
            'day_change_pct' => $this->dayChangePct,
            'source' => $this->source->value,
            // Pre-formatted with microseconds on purpose. Laravel's query
            // grammar formats DateTimeInterface bindings as 'Y-m-d H:i:s' with
            // no fractional part, so passing Carbon here would silently
            // truncate and destroy the lag this row exists to record.
            'quoted_at' => $this->quotedAt->format('Y-m-d H:i:s.u'),
            'received_at' => $this->receivedAt->format('Y-m-d H:i:s.u'),
        ];
    }
}
