<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\URL;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        // Force HTTPS URLs when deployed on Railway (or any production PaaS).
        // Railway terminates SSL at its reverse proxy and forwards requests via HTTP
        // internally — so without this, route() helpers generate http:// URLs,
        // which browsers block as Mixed Content on HTTPS pages.
        if (config('app.env') === 'production' || str_starts_with(env('APP_URL', ''), 'https://')) {
            URL::forceScheme('https');
        }
    }
}
