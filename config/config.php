<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Set the duration of response cache
    |--------------------------------------------------------------------------
    |
    | This option controls the validity of the cache in minutes. So after the
    | number of minutes set in this value has passed, the values are removed
    | form the cache.
    |
    */
    "cacheDuration" => env('ROUTE_CACHE_TIME', 15)
];
