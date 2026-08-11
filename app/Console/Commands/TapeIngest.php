<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Symbol;
use App\Services\Budget\CreditBudget;
use App\Services\Control\FeedControl;
use App\Services\Metrics\FeedMetrics;
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
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Collection;
use Ratchet\Client\Connector;
use React\EventLoop\Loop;

final class TapeIngest extends Command
{
    protected $signature = 'tape:ingest
        {--symbols= : Comma-separated tickers, overriding the watchlists}
        {--once : Run a bounded number of synchronous passes instead of the event loop}
        {--passes=1 : How many passes to run under --once}';

    protected $description = 'Ingest live quotes from the upstream feed';

    /** @var array<string, int> ticker => symbol id */
    private array $symbolIds = [];

    public function handle(
        Config $config,
        Connection $redis,
        CreditBudget $budget,
        TwelveDataClient $client,
        FeedControl $control,
        QuoteCache $cache,
        TickBuffer $buffer,
        FeedMetrics $metrics,
    ): int {
        $tickers = $this->resolveTickers();

        if ($tickers === []) {
            $this->warn('no symbols on any watchlist. add one to start the tape.');

            return self::SUCCESS;
        }

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
        );

        $onQuote = function (Quote $quote) use ($cache, $buffer, $metrics): void {
            $cache->put($quote);
            $metrics->recordLag($quote->lagMs());
            $metrics->recordTick();

            // A quote for a ticker we do not track is not an error — the
            // upstream can echo symbols we unsubscribed from mid-flight.
            if (isset($this->symbolIds[$quote->ticker])) {
                $buffer->add($quote, $this->symbolIds[$quote->ticker]);
            }
        };

        $manager->boot($tickers, $onQuote);

        $this->info(sprintf('tape:ingest running · driver %s · %d symbols', $manager->state()->value, count($tickers)));

        try {
            if ($this->option('once')) {
                $this->runBounded($manager, $buffer, (int) $this->option('passes'));
            } else {
                $this->runLoop($manager, $buffer);
            }
        } finally {
            // Without this every buffered tick below the flush threshold is
            // lost on shutdown.
            $buffer->flush();
            $manager->stopAll();
        }

        return self::SUCCESS;
    }

    private function runBounded(DriverManager $manager, TickBuffer $buffer, int $passes): void
    {
        for ($i = 0; $i < max(1, $passes); $i++) {
            $manager->supervise();
            $manager->current()->tick();
        }

        $buffer->flushIfDue();
    }

    private function runLoop(DriverManager $manager, TickBuffer $buffer): void
    {
        Loop::addPeriodicTimer(0.25, static function () use ($manager): void {
            $manager->supervise();
            $manager->current()->tick();
        });

        Loop::addPeriodicTimer(1.0, static function () use ($buffer): void {
            $buffer->flushIfDue();
        });

        foreach ([SIGTERM, SIGINT] as $signal) {
            Loop::addSignal($signal, static function () use ($buffer, $manager): void {
                $buffer->flush();
                $manager->stopAll();
                Loop::stop();
            });
        }

        Loop::run();
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
