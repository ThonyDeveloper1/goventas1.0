<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key'    => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel'              => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | RENIEC — Consulta de DNI (apiperu.dev)
    |--------------------------------------------------------------------------
    | Token must be set in .env as RENIEC_API_TOKEN.
    | The URL defaults to apiperu.dev — override only if you use a different
    | provider.  Token is NEVER stored in the database or exposed to clients.
    */
    'reniec' => [
        'url'   => env('RENIEC_API_URL', 'https://apiperu.dev/api'),
        'token' => env('RENIEC_API_TOKEN', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Google Maps — Server-side key (optional)
    |--------------------------------------------------------------------------
    | Used only for server-side geocoding / static maps.  The JavaScript Maps
    | key for the frontend is a Vite env var (VITE_GOOGLE_MAPS_KEY) and never
    | reaches this config file.
    */
    'google_maps' => [
        'server_key' => env('GOOGLE_MAPS_SERVER_KEY', ''),
    ],

];
