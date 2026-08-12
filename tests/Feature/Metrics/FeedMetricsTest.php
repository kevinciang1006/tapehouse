<?php

declare(strict_types=1);

use App\Services\Metrics\FeedMetrics;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Redis;

beforeEach(fn () => Redis::connection()->flushdb());
afterEach(fn () => CarbonImmutable::setTestNow());

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

it('blends in the previous minute so the count does not sawtooth to zero on rollover', function (): void {
    $m = new FeedMetrics(Redis::connection());

    // The previous wall-clock minute recorded 60 ticks.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-10 11:59:30'));
    for ($i = 0; $i < 60; $i++) {
        $m->recordTick();
    }

    // 15 seconds into the current minute: nothing recorded yet this minute,
    // but 45 of the trailing 60s window still falls inside the previous one.
    // A naive read of only the current-minute key would report 0 here.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-10 12:00:15'));

    expect($m->ticksPerMinute())->toBe(60 - 15);
});

it('clears every sample in the lag window', function (): void {
    $m = new FeedMetrics(Redis::connection());

    foreach (range(1, 10) as $ms) {
        $m->recordLag($ms);
    }

    $m->clearLag();

    expect(Redis::connection()->llen('tape:metrics:lag'))->toBe(0)
        ->and($m->lagPercentiles())->toBe(['p50' => 0, 'p95' => 0]);
});

it('produces the snapshot the ops panel reads', function (): void {
    $m = new FeedMetrics(Redis::connection());
    $m->recordLag(34);
    $m->recordTick();

    $snapshot = $m->snapshot();

    expect($snapshot)->toHaveKeys(['lag_p50', 'lag_p95', 'ticks_per_minute']);
});
