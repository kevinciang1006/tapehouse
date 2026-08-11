<?php

declare(strict_types=1);

namespace App\Services\Upstream;

use App\Enums\DriverState;
use App\Enums\FeedEventLevel;
use App\Events\FeedStateChanged;
use App\Models\FeedEvent;
use App\Services\Control\FeedControl;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Redis\Connections\Connection;

final class DriverManager
{
    private const STATE_KEY = 'tape:driver:state';

    private UpstreamDriver $current;

    /** @var list<string> */
    private array $tickers = [];

    /** @var (callable(DTO\Quote): void)|null */
    private $onQuote = null;

    private DriverState $state;

    private CarbonImmutable $since;

    private int $reconnects = 0;

    private int $demotions = 0;

    private ?CarbonImmutable $demotedAt = null;

    private bool $stopped = false;

    /**
     * @param  list<int>  $promotionBackoff
     */
    public function __construct(
        private readonly UpstreamDriver $primary,
        private readonly UpstreamDriver $fallback,
        private readonly FeedControl $control,
        private readonly Connection $redis,
        private readonly array $promotionBackoff,
        private readonly ?Dispatcher $events = null,
    ) {
        $this->current = $primary;
        $this->state = $primary->name();
        $this->since = CarbonImmutable::now();
    }

    /**
     * @param  list<string>  $tickers
     * @param  callable(DTO\Quote): void  $onQuote
     */
    public function boot(array $tickers, callable $onQuote): void
    {
        $this->tickers = $tickers;
        $this->onQuote = $onQuote;
        $this->current = $this->primary;
        $this->transitionTo($this->primary, 'feed.started', FeedEventLevel::Info, 'ingest started');
        $this->current->start($tickers, $onQuote);
    }

    public function current(): UpstreamDriver
    {
        return $this->current;
    }

    public function state(): DriverState
    {
        return $this->state;
    }

    public function since(): CarbonImmutable
    {
        return $this->since;
    }

    public function reconnects(): int
    {
        return $this->reconnects;
    }

    public function currentBackoffSeconds(): int
    {
        $index = min(max(0, $this->demotions - 1), count($this->promotionBackoff) - 1);

        return $this->promotionBackoff[$index];
    }

    /**
     * One supervision pass: honour the control flag, demote an unhealthy
     * primary, promote a recovered one once its backoff has elapsed.
     */
    public function supervise(): void
    {
        if ($this->control->isStopped()) {
            if (! $this->stopped) {
                $this->current->stop();
                $this->stopped = true;
                $this->state = DriverState::Stopped;
                $this->since = CarbonImmutable::now();
                $this->record('feed.stopped', FeedEventLevel::Warn, 'feed stopped by operator', []);
                $this->publish();
            }

            return;
        }

        if ($this->stopped) {
            $this->stopped = false;
            $this->current = $this->primary;
            $this->transitionTo($this->primary, 'feed.started', FeedEventLevel::Info, 'feed resumed by operator');
            $this->current->start($this->tickers, $this->onQuote ?? static fn () => null);

            return;
        }

        if ($this->current === $this->primary && ! $this->primary->isHealthy()) {
            $this->demote();

            return;
        }

        if ($this->current === $this->fallback && $this->primary->isHealthy() && $this->backoffElapsed()) {
            $this->promote();
        }
    }

    public function stopAll(): void
    {
        $this->primary->stop();
        $this->fallback->stop();
    }

    private function demote(): void
    {
        $error = $this->primary->lastError();

        $this->primary->stop();
        $this->current = $this->fallback;
        $this->demotions++;
        $this->reconnects++;
        $this->demotedAt = CarbonImmutable::now();

        $this->transitionTo(
            $this->fallback,
            'driver.demoted',
            FeedEventLevel::Warn,
            sprintf('%s demoted → %s. %s', $this->primary->name()->value, $this->fallback->name()->value, $error ?? 'unhealthy'),
            ['from' => $this->primary->name()->value, 'to' => $this->fallback->name()->value, 'error' => $error],
        );

        $this->fallback->start($this->tickers, $this->onQuote ?? static fn () => null);
    }

    private function promote(): void
    {
        $this->fallback->stop();
        $this->current = $this->primary;
        $this->demotedAt = null;

        $this->transitionTo(
            $this->primary,
            'driver.promoted',
            FeedEventLevel::Info,
            sprintf('%s promoted → %s', $this->fallback->name()->value, $this->primary->name()->value),
            ['from' => $this->fallback->name()->value, 'to' => $this->primary->name()->value],
        );

        $this->primary->start($this->tickers, $this->onQuote ?? static fn () => null);
    }

    private function backoffElapsed(): bool
    {
        if ($this->demotedAt === null) {
            return true;
        }

        return CarbonImmutable::now()->diffInSeconds($this->demotedAt, true) > $this->currentBackoffSeconds();
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function transitionTo(UpstreamDriver $driver, string $type, FeedEventLevel $level, string $message, array $context = []): void
    {
        $this->state = $driver->name();
        $this->since = CarbonImmutable::now();
        $this->record($type, $level, $message, $context);
        $this->publish();
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function record(string $type, FeedEventLevel $level, string $message, array $context): void
    {
        FeedEvent::create([
            'level' => $level,
            'type' => $type,
            'message' => $message,
            'context' => $context,
            'occurred_at' => CarbonImmutable::now(),
        ]);
    }

    private function publish(): void
    {
        $this->redis->hmset(self::STATE_KEY, [
            'driver' => $this->state->value,
            'since' => (string) $this->since->getTimestamp(),
            'reconnects' => (string) $this->reconnects,
            'last_error' => (string) ($this->current->lastError() ?? ''),
        ]);

        $this->events?->dispatch(new FeedStateChanged(
            $this->state,
            (int) CarbonImmutable::now()->diffInSeconds($this->since, true),
            $this->reconnects,
            $this->current->lastError(),
        ));
    }
}
