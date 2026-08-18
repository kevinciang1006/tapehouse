<?php

declare(strict_types=1);

use App\Services\Control\Exceptions\FeedControlLockedException;
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

it('refuses to stop or start when locked', function (): void {
    // Locked is how production runs (see TapehouseServiceProvider): the
    // control flag is one Redis key shared by every visitor to the public
    // demo, so stop()/start() must refuse rather than let one operator kill
    // the feed for everyone after them.
    $control = new FeedControl(Redis::connection(), locked: true);

    expect(fn () => $control->stop())->toThrow(FeedControlLockedException::class);
    expect(fn () => $control->start())->toThrow(FeedControlLockedException::class);
});

it('still reports state while locked', function (): void {
    (new FeedControl(Redis::connection()))->stop();

    expect((new FeedControl(Redis::connection(), locked: true))->isStopped())->toBeTrue();
});
