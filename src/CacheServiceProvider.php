<?php

namespace Eventix\Cache;

use Illuminate\Support\ServiceProvider;
use Illuminate\Foundation\Application;
use Eventix\Cache\Middleware\CacheMiddleware;

class CacheServiceProvider extends ServiceProvider
{

    private static $configPath = __DIR__ . '/../config/config.php';

    public $defer = true;

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return [CacheMiddleware::class];
    }

    public function register()
    {
        $this->app->singleton(CacheMiddleware::class, function ($app) {
            return new CacheMiddleware($app['config']->get('special-cache.cacheDuration'));
        });

        $this->mergeConfigFrom(self::$configPath, 'special-cache');
    }

    public function boot()
    {
        if (!$this->app instanceof Application || !$this->app->runningInConsole()) {
            return;
        }

        $this->publishes([self::$configPath => config_path('special-cache.php')]);
    }
}
