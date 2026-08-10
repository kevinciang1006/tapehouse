<?php

declare(strict_types=1);

namespace App\Services\Upstream;

use App\Enums\DriverState;
use App\Enums\TickSource;
use App\Services\Upstream\DTO\Quote;
use Carbon\CarbonImmutable;

/**
 * Generates a random walk so the tape has enough ticks to exercise the flash
 * during development and in the deployed demo, where an 8-credit-per-minute
 * trial key cannot.
 *
 * It reports itself as `simulated` everywhere — driver state, tick source and
 * feed events — and never as websocket or polling. The demo may be driven by
 * generated data; it must never claim that data is live.
 */
final class SimulatedDriver implements UpstreamDriver
{
    /** @var array<string, float> */
    private array $prices;

    /** @var list<string> */
    private array $tickers = [];

    /** @var (callable(Quote): void)|null */
    private $onQuote = null;

    private ?CarbonImmutable $lastTickAt = null;

    /**
     * @param  array<string, string>  $seedPrices  ticker => opening price
     */
    public function __construct(array $seedPrices, private readonly int $intervalMs)
    {
        $this->prices = array_map(static fn (string $p): float => (float) $p, $seedPrices);
    }

    public function name(): DriverState
    {
        return DriverState::Simulated;
    }

    public function start(array $tickers, callable $onQuote): void
    {
        $this->tickers = $tickers;
        $this->onQuote = $onQuote;
        $this->lastTickAt = null;

        foreach ($this->tickers as $ticker) {
            $this->prices[$ticker] ??= 100.0;
        }
    }

    public function tick(): void
    {
        if ($this->tickers === [] || $this->onQuote === null || ! $this->intervalElapsed()) {
            return;
        }

        $this->lastTickAt = CarbonImmutable::now();

        $ticker = $this->tickers[random_int(0, count($this->tickers) - 1)];
        $previous = $this->prices[$ticker];

        $step = $previous * (random_int(5, 900) / 1_000_000) * (random_int(0, 1) === 1 ? 1 : -1);
        $price = max(0.00001, $previous + $step);

        $this->prices[$ticker] = $price;

        $now = CarbonImmutable::now();

        ($this->onQuote)(new Quote(
            ticker: $ticker,
            price: number_format($price, 8, '.', ''),
            dayChange: number_format($price - $previous, 8, '.', ''),
            dayChangePct: number_format((($price - $previous) / $previous) * 100, 4, '.', ''),
            source: TickSource::Simulated,
            quotedAt: $now,
            receivedAt: $now,
        ));
    }

    public function stop(): void
    {
        $this->tickers = [];
        $this->onQuote = null;
    }

    public function isHealthy(): bool
    {
        return true;
    }

    public function lastError(): ?string
    {
        return null;
    }

    private function intervalElapsed(): bool
    {
        if ($this->intervalMs <= 0 || $this->lastTickAt === null) {
            return true;
        }

        $elapsedMs = (CarbonImmutable::now()->getPreciseTimestamp(3) - $this->lastTickAt->getPreciseTimestamp(3));

        return $elapsedMs >= $this->intervalMs;
    }
}
