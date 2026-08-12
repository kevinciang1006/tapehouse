<?php

declare(strict_types=1);

namespace App\Enums;

enum DriverState: string
{
    case WebSocket = 'websocket';
    case Polling = 'polling';
    case Simulated = 'simulated';
    case Stopped = 'stopped';

    public function isLive(): bool
    {
        return $this !== self::Stopped;
    }

    /**
     * Seconds without a tick before a symbol reads as stale. A polling feed on
     * a trial key refreshes far slower than a streaming one, so a single fixed
     * threshold would mark the whole tape stale purely because of the plan.
     */
    public function staleThreshold(): int
    {
        return match ($this) {
            self::WebSocket => (int) config('tapehouse.stale.websocket'),
            self::Polling => (int) config('tapehouse.stale.polling'),
            self::Simulated => (int) config('tapehouse.stale.simulated'),
            self::Stopped => PHP_INT_MAX,
        };
    }
}
