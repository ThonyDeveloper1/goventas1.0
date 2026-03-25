<?php

namespace App\Providers;

use App\Models\Client;
use App\Observers\ClientObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Client::observe(ClientObserver::class);

        // Avoid blocking web requests (e.g. login) with MikroTik pre-warm.
        // Keep this only for console lifecycle where it won't impact users.
        if ($this->app->runningInConsole() && ! $this->app->runningUnitTests()) {
            $this->app->booted(function () {
                try {
                    app(\App\Services\MikrotikIspService::class)->preWarmCache();
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('[AppServiceProvider] Cache pre-warm failed: ' . $e->getMessage());
                }
            });
        }
    }
}
