<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Cache Store
    |--------------------------------------------------------------------------
    |
    | CACHE_DRIVER is the variable name used by earlier releases of this
    | application and is still honoured; CACHE_STORE takes precedence.
    |
    */

    'default' => env('CACHE_STORE', env('CACHE_DRIVER', 'file')),

    /*
    |--------------------------------------------------------------------------
    | Cache Stores
    |--------------------------------------------------------------------------
    */

    'stores' => [

        'array' => [
            'driver' => 'array',
            'serialize' => false,
        ],

        'file' => [
            'driver' => 'file',
            'path' => storage_path('framework/cache'),
            'lock_path' => storage_path('framework/cache'),
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_CACHE_CONNECTION', 'default'),
            'lock_connection' => env('REDIS_CACHE_LOCK_CONNECTION', 'default'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Key Prefix
    |--------------------------------------------------------------------------
    |
    | Kept at the value used by earlier releases so existing cache keys
    | (e.g. IP block markers) remain valid after the upgrade.
    |
    */

    'prefix' => env('CACHE_PREFIX', 'laravel'),

];
