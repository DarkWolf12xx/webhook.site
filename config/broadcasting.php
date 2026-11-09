<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Broadcaster
    |--------------------------------------------------------------------------
    |
    | BROADCAST_DRIVER is the variable name used by earlier releases of this
    | application and is still honoured; BROADCAST_CONNECTION takes precedence.
    |
    */

    'default' => env('BROADCAST_CONNECTION', env('BROADCAST_DRIVER', 'redis')),

    // How the frontend connects to laravel-echo-server: 'path' (/socket.io on the
    // same host, proxied by nginx) or 'port' (host:6001).
    'echo_host_mode' => env('ECHO_HOST_MODE', 'path'),

    /*
    |--------------------------------------------------------------------------
    | Broadcast Connections
    |--------------------------------------------------------------------------
    */

    'connections' => [

        'pusher' => [
            'driver' => 'pusher',
            'key' => env('PUSHER_KEY', env('PUSHER_APP_KEY')),
            'secret' => env('PUSHER_SECRET', env('PUSHER_APP_SECRET')),
            'app_id' => env('PUSHER_APP_ID'),
            'options' => [
                'cluster' => env('PUSHER_CLUSTER', env('PUSHER_APP_CLUSTER', 'eu')),
                'useTLS' => true,
            ],
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_BROADCAST_CONNECTION', 'default'),
        ],

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

];
