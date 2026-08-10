<?php

declare(strict_types=1);

use App\Services\Metrics\FeedMetrics;
use Illuminate\Support\Facades\Redis;

beforeEach(fn () => Redis::connection()->flushdb());

it('reports zero percentiles with no samples', function (): void {
    expect((new FeedMetrics(Redis::connection()))->lagPercentiles())->toBe(['p50' => 0, 'p95' => 0]);
});

it('computes p50 and p95 over the window', function (): void {
    $m = new FeedMetrics(Redis::connection());

    foreach (range(1, 100) as $ms) {
        $m->recordLag($ms);
    }

    $p = $m->lagPercentiles();

    expect($p['p50'])->toBeGreaterThanOrEqual(49)->toBeLessThanOrEqual(51)
        ->and($p['p95'])->toBeGreaterThanOrEqual(94)->toBeLessThanOrEqual(96);
});

it('trims the lag window to 500 samples', function (): void {
    $m = new FeedMetrics(Redis::connection());

    foreach (range(1, 600) as $ms) {
        $m->recordLag($ms);
    }

    expect(Redis::connection()->llen('tape:metrics:lag'))->toBe(500);
});

it('counts ticks per minute', function (): void {
    $m = new FeedMetrics(Redis::connection());

    $m->recordTick();
    $m->recordTick();
    $m->recordTick();

    expect($m->ticksPerMinute())->toBe(3);
});

it('produces the snapshot the ops panel reads', function (): void {
    $m = new FeedMetrics(Redis::connection());
    $m->recordLag(34);
    $m->recordTick();

    $snapshot = $m->snapshot();

    expect($snapshot)->toHaveKeys(['lag_p50', 'lag_p95', 'ticks_per_minute']);
});
