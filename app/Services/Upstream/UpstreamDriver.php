<?php

declare(strict_types=1);

namespace App\Services\Upstream;

use App\Enums\DriverState;
use App\Services\Upstream\DTO\Quote;

interface UpstreamDriver
{
    public function name(): DriverState;

    /**
     * @param  list<string>  $tickers
     * @param  callable(Quote): void  $onQuote
     */
    public function start(array $tickers, callable $onQuote): void;

    /**
     * One iteration of scheduled work. Must not block: a push driver checks
     * liveness here and does no I/O at all.
     */
    public function tick(): void;

    public function stop(): void;

    public function isHealthy(): bool;

    public function lastError(): ?string;
}
