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

    public function ticksPerMinute(): int
    {
        return (int) ($this->redis->get($this->minuteKey()) ?? 0);
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
        return 'tape:metrics:ticks_minute:'.CarbonImmutable::now()->format('YmdHi');
    }
}
