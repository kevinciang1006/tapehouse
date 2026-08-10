<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Budget\CreditBudget;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\ServiceProvider;

class TapehouseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CreditBudget::class, function ($app): CreditBudget {
            /** @var Config $config */
            $config = $app->make('config');

            /** @var RedisManager $redis */
            $redis = $app->make('redis');

            /** @var Connection $connection */
            $connection = $redis->connection();

            return new CreditBudget(
                $connection,
                (int) $config->get('tapehouse.budget.capacity'),
                (int) $config->get('tapehouse.budget.refill_per_minute'),
            );
        });
    }
}
