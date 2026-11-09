<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | The application does not store files. The disks are declared explicitly
    | so that the framework's defaults do not apply: Laravel 11+ marks the
    | "local" disk as "serve" => true, which registers GET/PUT storage/{path}
    | routes (signed URL downloads/uploads) that this application never uses.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app'),
            'serve' => false,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

    ],

    'links' => [],

];
