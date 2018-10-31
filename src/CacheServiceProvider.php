<?php

namespace Eventix\Cache;

use Illuminate\Support\ServiceProvider;
use Illuminate\Foundation\Application;
use Eventix\Cache\Middleware\CacheMiddleware;

class CacheServiceProvider extends ServiceProvider {

    public $defer = true;

    public function boot() {
        if ($this->app->runningInConsole())
            $this->commands([ReservationExpireHandler::class]);
    }
}
