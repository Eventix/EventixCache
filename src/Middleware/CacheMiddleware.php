<?php

namespace Eventix\Cache\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Cache;

class CacheMiddleware
{

    /**
     * The validity of the cache in minutes
     *
     * @var int
     */
    private $duration = 0;

    /**
     * CacheMiddleware constructor.
     *
     * @param int $duration The validity of the cache in minutes
     */
    public function __construct($duration = 0)
    {
        $this->duration = 1 * $duration;
    }

    /**
     * Handle the call to the middleware, retrieves the cached version iff it exists
     *
     * @param Request $request The request received by laravel
     * @param Closure $next The next Middleware to call
     * @param null $duration The duration in minutes, this is the parameter set in the call
     * @return mixed The cached version, or the actual content
     */
    public function handle(Request $request, Closure $next, $duration = null)
    {
        $this->duration = 1 * ($duration ?? $this->duration ?? 15);
        $key = self::buildKey($request);

        return Cache::has($key)
            ? Cache::get($key)
            : $next($request);
    }

    /**
     * Store the response in cache after the request has finished to not hinder the response time
     *
     * @param Request $request The request sent by the browser
     * @param Response $response The response sent back to the browser
     */
    public function terminate(Request $request, Response $response)
    {
        $key = self::buildKey($request);

        if (!Cache::has($key)) {
            Cache::put($key, $response, $this->duration);
        }
    }

    /**
     * Builds the key which is used to store the content
     *
     * @param Request $request The request sent by the browser
     * @return string The key of the content to store
     */
    private static function buildKey(Request $request)
    {
        return 'EventixCache_' . str_slug($request->getUri());
    }
}
