<?php

declare(strict_types=1);

namespace App\Services\Control;

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

    public function __construct(private Connection $redis) {}

    public function stop(): void
    {
        $this->redis->set(self::KEY, self::STOPPED);
    }

    public function start(): void
    {
        $this->redis->set(self::KEY, self::RUNNING);
    }

    public function isStopped(): bool
    {
        return $this->redis->get(self::KEY) === self::STOPPED;
    }
}
