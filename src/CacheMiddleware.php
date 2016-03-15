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
        Cache::remember(self::buildKey($request), $response->getContent());
    }

    private static function buildKey(Request $request)
    {
        return 'EventixCache_' . str_slug($request->getUri());
    }
}
