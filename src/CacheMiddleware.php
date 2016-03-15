<?php

namespace Eventix\Cache;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Cache;

class CacheMiddleware
{

    public function handle(Request $request, Closure $next)
    {
        $key = self::buildKey($request);

        return Cache::has($key)
            ? Cache::get($key)
            : $next($request);
    }

    public function terminate(Request $request, Response $response)
    {
        $key = self::buildKey($request);

        if(!Cache::has($key))
            Cache::put($key, $response->getContent(), 1);
    }

    private static function buildKey(Request $request)
    {
        return 'EventixCache_' . str_slug($request->getUri());
    }
}
