<?php

declare(strict_types=1);

namespace App\Services\Quotes;

use App\Events\QuotesUpdated;
use App\Services\Upstream\DTO\Quote;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Coalesces ticks into one broadcast per window per user.
 *
 * A single fast-moving symbol can tick many times a second. One frame per tick
 * would saturate the socket and give the browser more repaints than a human
 * eye can resolve, so the window collapses them — keeping only the latest
 * price per ticker, because an intermediate price nobody rendered is not worth
 * a frame.
 */
final class QuoteBroadcaster
{
    /** @var array<int, array<string, Quote>> userId => ticker => latest quote */
    private array $pending = [];

    private ?CarbonImmutable $windowOpenedAt = null;

    public function __construct(
        private readonly Dispatcher $events,
        private readonly int $coalesceMs,
    ) {}

    public function add(Quote $quote, int $userId): void
    {
        $this->windowOpenedAt ??= CarbonImmutable::now();
        $this->pending[$userId][$quote->ticker] = $quote;
    }

    public function flushIfDue(): int
    {
        if ($this->pending === [] || $this->windowOpenedAt === null) {
            return 0;
        }

        $elapsedMs = CarbonImmutable::now()->getPreciseTimestamp(3) - $this->windowOpenedAt->getPreciseTimestamp(3);

        return $elapsedMs >= $this->coalesceMs ? $this->flush() : 0;
    }

    public function flush(): int
    {
        if ($this->pending === []) {
            return 0;
        }

        $sent = 0;

        foreach ($this->pending as $userId => $quotes) {
            $payload = [];

            foreach ($quotes as $quote) {
                $payload[] = [
                    'ticker' => $quote->ticker,
                    'price' => $quote->price,
                    'day_change' => $quote->dayChange,
                    'day_change_pct' => $quote->dayChangePct,
                    'source' => $quote->source->value,
                    'quoted_at' => $quote->quotedAt->format('Y-m-d\TH:i:s.uP'),
                ];
                $sent++;
            }

            $this->events->dispatch(new QuotesUpdated($userId, $payload));
        }

        $this->pending = [];
        $this->windowOpenedAt = null;

        return $sent;
    }
}
