<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\AlertRule;
use App\Models\Watchlist;
use App\Policies\AlertRulePolicy;
use App\Policies\WatchlistPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Watchlist::class, WatchlistPolicy::class);
        Gate::policy(AlertRule::class, AlertRulePolicy::class);

        // Belt-and-suspenders alongside bootstrap/app.php's trustProxies():
        // that's the actual fix for Railway's HTTP-behind-TLS edge, this is
        // a cheap fallback in case X-Forwarded-Proto is ever stripped
        // between the edge and this container.
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }
    }
}
