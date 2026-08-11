<?php

declare(strict_types=1);

namespace App\Services\Upstream;

use App\Enums\DriverState;
use App\Services\Budget\CreditBudget;
use App\Services\Upstream\DTO\Quote;
use App\Services\Upstream\Exceptions\UpstreamAuthException;
use Carbon\CarbonImmutable;
use Illuminate\Redis\Connections\Connection;
use Throwable;

final class PollingDriver implements UpstreamDriver
{
    private const CURSOR_KEY = 'tape:poll:cursor';

    /** @var list<string> */
    private array $tickers = [];

    /** @var (callable(Quote): void)|null */
    private $onQuote = null;

    private ?string $lastError = null;

    private bool $authRejected = false;

    private ?CarbonImmutable $lastPolledAt = null;

    public function __construct(
        private readonly TwelveDataClient $client,
        private readonly CreditBudget $budget,
        private readonly Connection $redis,
        private readonly int $batchSize,
        private readonly int $intervalSeconds,
    ) {}

    public function name(): DriverState
    {
        return DriverState::Polling;
    }

    public function start(array $tickers, callable $onQuote): void
    {
        $this->tickers = array_values($tickers);
        $this->onQuote = $onQuote;
        $this->authRejected = false;
        $this->lastError = null;
    }

    public function tick(): void
    {
        if ($this->tickers === [] || $this->onQuote === null || ! $this->intervalElapsed()) {
            return;
        }

        $cursor = $this->cursor();
        $slice = $this->sliceFrom($cursor);

        // One credit per symbol, not per request. A partial grant polls fewer
        // symbols rather than overspending or stalling the whole slice.
        $granted = $this->budget->tryConsume(count($slice));

        if ($granted <= 0) {
            return;
        }

        $slice = array_slice($slice, 0, $granted);
        $this->lastPolledAt = CarbonImmutable::now();

        // Advance by what was actually polled. Advancing by the requested
        // slice would permanently starve the symbols the budget could not
        // cover on this pass.
        $this->setCursor(($cursor + count($slice)) % max(1, count($this->tickers)));

        try {
            foreach ($this->client->quotes($slice) as $quote) {
                ($this->onQuote)($quote);
            }

            $this->lastError = null;
        } catch (UpstreamAuthException $e) {
            $this->authRejected = true;
            $this->lastError = $e->getMessage();
        } catch (Throwable $e) {
            // Transient failures do not make polling unhealthy: it is the
            // fallback of last resort and there is nowhere to demote to.
            $this->lastError = $e->getMessage();
        }
    }

    public function stop(): void
    {
        $this->tickers = [];
        $this->onQuote = null;
    }

    public function isHealthy(): bool
    {
        return ! $this->authRejected;
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * @return list<string>
     */
    private function sliceFrom(int $cursor): array
    {
        if ($this->tickers === []) {
            return [];
        }

        return array_slice($this->tickers, $cursor, $this->batchSize);
    }

    private function cursor(): int
    {
        $count = count($this->tickers);

        if ($count === 0) {
            return 0;
        }

        return ((int) ($this->redis->get(self::CURSOR_KEY) ?? 0)) % $count;
    }

    private function setCursor(int $cursor): void
    {
        $this->redis->set(self::CURSOR_KEY, (string) $cursor);
    }

    private function intervalElapsed(): bool
    {
        if ($this->intervalSeconds <= 0 || $this->lastPolledAt === null) {
            return true;
        }

        return CarbonImmutable::now()->diffInSeconds($this->lastPolledAt, true) >= $this->intervalSeconds;
    }
}
