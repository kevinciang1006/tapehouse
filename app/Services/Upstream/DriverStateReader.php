<?php

declare(strict_types=1);

namespace App\Services\Upstream;

use App\Enums\DriverState;
use Illuminate\Redis\Connections\Connection;

/**
 * Reads back the state hash `DriverManager` publishes.
 *
 * The manager lives in the ingest process; the web process serving the ops
 * panel has no instance of it. Redis is the only thing both processes share.
 */
final readonly class DriverStateReader
{
    private const KEY = 'tape:driver:state';

    public function __construct(private Connection $redis) {}

    /**
     * @return array{driver: DriverState, since: int, reconnects: int, last_error: string|null}
     */
    public function read(): array
    {
        /** @var array<string, string> $hash */
        $hash = $this->redis->hgetall(self::KEY);

        // No hash means the ingest process has never run. That is `stopped`,
        // not an error — the console renders it as a dark status dot.
        $driver = DriverState::tryFrom($hash['driver'] ?? '') ?? DriverState::Stopped;
        $lastError = ($hash['last_error'] ?? '') === '' ? null : $hash['last_error'];

        return [
            'driver' => $driver,
            'since' => (int) ($hash['since'] ?? 0),
            'reconnects' => (int) ($hash['reconnects'] ?? 0),
            'last_error' => $lastError,
        ];
    }
}
