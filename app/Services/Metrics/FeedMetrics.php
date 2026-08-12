<?php

declare(strict_types=1);

namespace App\Services\Metrics;

use Carbon\CarbonImmutable;
use Illuminate\Redis\Connections\Connection;

final readonly class FeedMetrics
{
    private const LAG_KEY = 'tape:metrics:lag';

    private const LAG_WINDOW = 500;

    public function __construct(private Connection $redis) {}

    public function recordLag(int $ms): void
    {
        $this->redis->lpush(self::LAG_KEY, (string) $ms);
        $this->redis->ltrim(self::LAG_KEY, 0, self::LAG_WINDOW - 1);
    }

    /**
     * Drops every sample in the rolling lag window. The window has no TTL,
     * so without an explicit clear a driver switch (e.g. WebSocket ->
     * polling on demotion) leaves the previous driver's lag samples in the
     * list — up to 500 of them — and the ops panel reports a stale
     * percentile blended with, or entirely made of, a driver that is no
     * longer running. Call this on every driver transition, not just
     * demotion/promotion.
     */
    public function clearLag(): void
    {
        $this->redis->del(self::LAG_KEY);
    }

    public function recordTick(): void
    {
        $key = $this->minuteKey();
        $this->redis->incr($key);
        $this->redis->expire($key, 300);
    }

    /**
     * @return array{p50: int, p95: int}
     */
    public function lagPercentiles(): array
    {
        /** @var list<string> $samples */
        $samples = $this->redis->lrange(self::LAG_KEY, 0, -1);

        if ($samples === []) {
            return ['p50' => 0, 'p95' => 0];
        }

        $values = array_map('intval', $samples);
        sort($values);

        return [
            'p50' => $this->percentile($values, 0.50),
            'p95' => $this->percentile($values, 0.95),
        ];
    }

    /**
     * A rolling estimate rather than a read of the current wall-clock minute
     * bucket alone: reading only that key reports ~12 at second 3 of the
     * minute and ~240 at second 59, then drops to 0 the instant the minute
     * rolls over — a sawtooth the ops panel would read as a repeated outage.
     * Blends in the previous minute's count, weighted by how much of the
     * trailing 60s window still falls inside it.
     */
    public function ticksPerMinute(): int
    {
        $now = CarbonImmutable::now();
        $secondOfMinute = (int) $now->format('s');

        $current = (int) ($this->redis->get($this->minuteKeyFor($now)) ?? 0);
        $previous = (int) ($this->redis->get($this->minuteKeyFor($now->subMinute())) ?? 0);

        return $current + (int) round($previous * ((60 - $secondOfMinute) / 60));
    }

    /**
     * @return array{lag_p50: int, lag_p95: int, ticks_per_minute: int}
     */
    public function snapshot(): array
    {
        $lag = $this->lagPercentiles();

        return [
            'lag_p50' => $lag['p50'],
            'lag_p95' => $lag['p95'],
            'ticks_per_minute' => $this->ticksPerMinute(),
        ];
    }

    /**
     * @param  list<int>  $sorted
     */
    private function percentile(array $sorted, float $q): int
    {
        $index = (int) ceil($q * count($sorted)) - 1;

        return $sorted[max(0, min($index, count($sorted) - 1))];
    }

    private function minuteKey(): string
    {
        return $this->minuteKeyFor(CarbonImmutable::now());
    }

    private function minuteKeyFor(CarbonImmutable $at): string
    {
        return 'tape:metrics:ticks_minute:'.$at->format('YmdHi');
    }
}
