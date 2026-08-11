<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Budget\CreditBudget;
use App\Services\Control\FeedControl;
use App\Services\Metrics\FeedMetrics;
use App\Services\Quotes\QuoteBroadcaster;
use App\Services\Quotes\QuoteCache;
use App\Services\Quotes\TickBuffer;
use App\Services\Upstream\TwelveDataClient;
use GuzzleHttp\Client;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\ServiceProvider;

class TapehouseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CreditBudget::class, function ($app): CreditBudget {
            return new CreditBudget(
                $this->redis($app),
                (int) $this->config($app)->get('tapehouse.budget.capacity'),
                (int) $this->config($app)->get('tapehouse.budget.refill_per_minute'),
            );
        });

        $this->app->singleton(FeedControl::class, fn ($app): FeedControl => new FeedControl($this->redis($app)));

        $this->app->singleton(QuoteCache::class, fn ($app): QuoteCache => new QuoteCache($this->redis($app)));

        $this->app->singleton(FeedMetrics::class, fn ($app): FeedMetrics => new FeedMetrics($this->redis($app)));

        $this->app->singleton(TickBuffer::class, function ($app): TickBuffer {
            return new TickBuffer(
                $app->make('db')->connection(),
                (int) $this->config($app)->get('tapehouse.ticks.buffer_size'),
                (int) $this->config($app)->get('tapehouse.ticks.flush_ms'),
            );
        });

        $this->app->singleton(TwelveDataClient::class, function ($app): TwelveDataClient {
            return new TwelveDataClient(
                new Client,
                (string) $this->config($app)->get('tapehouse.api_key'),
                (string) $this->config($app)->get('tapehouse.rest_url'),
            );
        });

        $this->app->singleton(QuoteBroadcaster::class, function ($app): QuoteBroadcaster {
            return new QuoteBroadcaster(
                $app->make('events'),
                (int) $this->config($app)->get('tapehouse.broadcast.coalesce_ms'),
            );
        });
    }

    private function config(Container $app): Config
    {
        /** @var Config $config */
        $config = $app->make('config');

        return $config;
    }

    private function redis(Container $app): Connection
    {
        /** @var RedisManager $redis */
        $redis = $app->make('redis');

        /** @var Connection $connection */
        $connection = $redis->connection();

        return $connection;
    }
}
