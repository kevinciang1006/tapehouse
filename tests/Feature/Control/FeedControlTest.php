<?php

declare(strict_types=1);

use App\Services\Control\FeedControl;
use Illuminate\Support\Facades\Redis;

beforeEach(fn () => Redis::connection()->flushdb());

it('runs by default', function (): void {
    expect((new FeedControl(Redis::connection()))->isStopped())->toBeFalse();
});

it('stops and starts across separate instances', function (): void {
    // The web process and the ingest loop are different processes — in
    // production, different containers — so the flag must live in Redis, not
    // in object state.
    (new FeedControl(Redis::connection()))->stop();

    expect((new FeedControl(Redis::connection()))->isStopped())->toBeTrue();

    (new FeedControl(Redis::connection()))->start();

    expect((new FeedControl(Redis::connection()))->isStopped())->toBeFalse();
});
