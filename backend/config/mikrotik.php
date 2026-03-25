<?php

return [
    /*
    |--------------------------------------------------------------------------
    | MikroTik RouterOS Connection
    |--------------------------------------------------------------------------
    |
    | Leave MIKROTIK_HOST empty to use mock mode (development).
    | In production, set all values in .env
    |
    */

    'host'     => env('MIKROTIK_HOST', ''),
    'port'     => env('MIKROTIK_PORT', 8728),
    'user'     => env('MIKROTIK_USER', 'admin'),
    'password' => env('MIKROTIK_PASSWORD', ''),

    /*
    |--------------------------------------------------------------------------
    | Sync interval (minutes)
    |--------------------------------------------------------------------------
    */
    'sync_interval' => env('MIKROTIK_SYNC_INTERVAL', 5),

    /*
    |--------------------------------------------------------------------------
    | Cache TTL (seconds)
    |--------------------------------------------------------------------------
    */
    'cache_ttl' => env('MIKROTIK_CACHE_TTL', 300),

    /*
    |--------------------------------------------------------------------------
    | Default PPPoE profile
    |--------------------------------------------------------------------------
    */
    'default_profile' => env('MIKROTIK_DEFAULT_PROFILE', 'default'),
];
