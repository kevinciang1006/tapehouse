<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\EvaluateAlerts;
use App\Models\Symbol;
use App\Models\Watchlist;
use App\Services\Budget\CreditBudget;
use App\Services\Control\FeedControl;
use App\Services\Metrics\FeedMetrics;
use App\Services\Quotes\QuoteBroadcaster;
use App\Services\Quotes\QuoteCache;
use App\Services\Quotes\TickBuffer;
use App\Services\Upstream\DriverManager;
use App\Services\Upstream\DTO\Quote;
use App\Services\Upstream\PollingDriver;
use App\Services\Upstream\SimulatedDriver;
use App\Services\Upstream\TwelveDataClient;
use App\Services\Upstream\UpstreamDriver;
use App\Services\Upstream\WebSocketDriver;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Collection;
use Ratchet\Client\Connector;
use React\EventLoop\Loop;
use Throwable;

final class TapeIngest extends Command
{
    protected $signature = 'tape:ingest
        {--symbols= : Comma-separated tickers, overriding the watchlists}
        {--once : Run a bounded number of synchronous passes instead of the event loop}
        {--passes=1 : How many passes to run under --once}';

    protected $description = 'Ingest live quotes from the upstream feed';

    /** @var array<string, int> ticker => symbol id */
    private array $symbolIds = [];

    /**
     * Latest sample per symbol since the last dispatch, keyed by symbol id.
     * Evaluation runs on the queue and is dispatched once per broadcast
     * flush, never per tick — a slow rule must never be able to stall
     * ingest.
     *
     * @var array<int, array{symbol_id: int, price: string, day_change_pct: string|null}>
     */
    private array $alertSamples = [];

    public function handle(
        Config $config,
        Connection $redis,
        CreditBudget $budget,
        TwelveDataClient $client,
        FeedControl $control,
        QuoteCache $cache,
        TickBuffer $buffer,
        FeedMetrics $metrics,
        QuoteBroadcaster $broadcaster,
        Dispatcher $events,
    ): int {
        $tickers = $this->resolveTickers();

        if ($tickers === []) {
            $this->warn('no symbols on any watchlist. add one to start the tape.');

            return self::SUCCESS;
        }

        $userId = (int) (Watchlist::query()->value('user_id') ?? 0);

        $primary = $this->buildPrimary($config, $client, $budget, $redis, $tickers);
        $fallback = new PollingDriver(
            $client,
            $budget,
            $redis,
            (int) $config->get('tapehouse.poll.batch_size'),
            (int) $config->get('tapehouse.poll.interval_seconds'),
        );

        $manager = new DriverManager(
            $primary,
            $fallback,
            $control,
            $redis,
            (array) $config->get('tapehouse.driver.promotion_backoff'),
            $events,
        );

        $onQuote = function (Quote $quote) use ($cache, $buffer, $metrics, $broadcaster, $userId): void {
            $cache->put($quote);
            $metrics->recordLag($quote->lagMs());
            $metrics->recordTick();

            // A quote for a ticker we do not track is not an error — the
            // upstream can echo symbols we unsubscribed from mid-flight.
            if (isset($this->symbolIds[$quote->ticker])) {
                $symbolId = $this->symbolIds[$quote->ticker];
                $buffer->add($quote, $symbolId);
                $this->alertSamples[$symbolId] = [
                    'symbol_id' => $symbolId,
                    'price' => $quote->price,
                    'day_change_pct' => $quote->dayChangePct,
                ];
            }

            if ($userId > 0) {
                $broadcaster->add($quote, $userId);
            }
        };

        $manager->boot($tickers, $onQuote);

        $this->info(sprintf('tape:ingest running · driver %s · %d symbols', $manager->state()->value, count($tickers)));

        try {
            if ($this->option('once')) {
                $this->runBounded($manager, $buffer, $broadcaster, (int) $this->option('passes'));
            } else {
                $this->runLoop($manager, $buffer, $broadcaster);
            }
        } finally {
            // Without this every buffered tick below the flush threshold is
            // lost on shutdown. Wrapped so a failure here (e.g. Postgres is
            // down) does not replace whatever exception sent us into this
            // finally block in the first place.
            try {
                $buffer->flush();
                $broadcaster->flush();
                $this->dispatchAlerts();
            } catch (Throwable $e) {
                $this->error('flush on shutdown failed: '.$e->getMessage());
            }
            $manager->stopAll();
        }

        return self::SUCCESS;
    }

    private function runBounded(DriverManager $manager, TickBuffer $buffer, QuoteBroadcaster $broadcaster, int $passes): void
    {
        for ($i = 0; $i < max(1, $passes); $i++) {
            $manager->supervise();
            $manager->current()->tick();
        }

        $buffer->flushIfDue();
        $broadcaster->flushIfDue();
        $this->dispatchAlerts();
    }

    private function runLoop(DriverManager $manager, TickBuffer $buffer, QuoteBroadcaster $broadcaster): void
    {
        Loop::addPeriodicTimer(0.25, static function () use ($manager): void {
            $manager->supervise();
            $manager->current()->tick();
        });

        Loop::addPeriodicTimer(1.0, function () use ($buffer, $broadcaster): void {
            $buffer->flushIfDue();
            $broadcaster->flushIfDue();
            $this->dispatchAlerts();
        });

        foreach ([SIGTERM, SIGINT] as $signal) {
            Loop::addSignal($signal, function () use ($buffer, $broadcaster, $manager): void {
                $buffer->flush();
                $broadcaster->flush();
                $this->dispatchAlerts();
                $manager->stopAll();
                Loop::stop();
            });
        }

        Loop::run();
    }

    /**
     * Dispatches whatever quote samples have accumulated since the last
     * flush, tied to the same call sites as the broadcaster's own flush so
     * evaluation runs on the same coalesced cadence as quote broadcasts —
     * batched onto the queue and never inline on the ingest path. A slow
     * rule must never be able to stall ingest.
     */
    private function dispatchAlerts(): void
    {
        if ($this->alertSamples === []) {
            return;
        }

        EvaluateAlerts::dispatch(array_values($this->alertSamples));
        $this->alertSamples = [];
    }

    /**
     * @param  list<string>  $tickers
     */
    private function buildPrimary(
        Config $config,
        TwelveDataClient $client,
        CreditBudget $budget,
        Connection $redis,
        array $tickers,
    ): UpstreamDriver {
        if ((bool) $config->get('tapehouse.simulator.enabled')) {
            $seed = [];
            foreach ($tickers as $ticker) {
                $seed[$ticker] = '100.00';
            }

            return new SimulatedDriver($seed, (int) $config->get('tapehouse.simulator.interval_ms'));
        }

        if ((bool) $config->get('tapehouse.ws_enabled')) {
            return new WebSocketDriver(
                new Connector,
                (string) $config->get('tapehouse.ws_url'),
                (string) $config->get('tapehouse.api_key'),
                90,
                (int) $config->get('tapehouse.driver.failure_threshold'),
            );
        }

        return new PollingDriver(
            $client,
            $budget,
            $redis,
            (int) $config->get('tapehouse.poll.batch_size'),
            (int) $config->get('tapehouse.poll.interval_seconds'),
        );
    }

    /**
     * @return list<string>
     */
    private function resolveTickers(): array
    {
        $option = (string) ($this->option('symbols') ?? '');

        $query = Symbol::query()->where('is_active', true);

        if ($option !== '') {
            $query->whereIn('ticker', array_map('trim', explode(',', $option)));
        } else {
            $query->whereHas('watchlists');
        }

        /** @var Collection<int, Symbol> $symbols */
        $symbols = $query->get();

        $this->symbolIds = $symbols->pluck('id', 'ticker')->all();

        return array_values($symbols->pluck('ticker')->all());
    }
}
