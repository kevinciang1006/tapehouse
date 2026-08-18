<?php

declare(strict_types=1);

namespace App\Services\Control;

use App\Services\Control\Exceptions\FeedControlLockedException;
use Illuminate\Redis\Connections\Connection;

/**
 * The Stop feed button's backing state.
 *
 * The web request and the ingest loop are separate processes — separate
 * containers in production — so this cannot be a signal or in-process state.
 * A Redis key the loop reads each pass is the mechanism that works across both.
 */
final readonly class FeedControl
{
    private const KEY = 'tape:control:state';

    private const STOPPED = 'stopped';

    private const RUNNING = 'running';

    /**
     * @param  bool  $locked  True in production (see TapehouseServiceProvider):
     *                        the flag is one Redis key shared by every visitor,
     *                        so any operator can stop the feed for everyone
     *                        else on the public demo. stop()/start() refuse to
     *                        run rather than let that happen; isStopped() stays
     *                        readable regardless.
     */
    public function __construct(
        private Connection $redis,
        private bool $locked = false,
    ) {}

    public function stop(): void
    {
        $this->guardLocked();
        $this->redis->set(self::KEY, self::STOPPED);
    }

    public function start(): void
    {
        $this->guardLocked();
        $this->redis->set(self::KEY, self::RUNNING);
    }

    public function isStopped(): bool
    {
        return $this->redis->get(self::KEY) === self::STOPPED;
    }

    private function guardLocked(): void
    {
        if ($this->locked) {
            throw new FeedControlLockedException('Feed control is locked on this demo instance.');
        }
    }
}
