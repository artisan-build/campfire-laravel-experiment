<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Broadcast Connection Name
    |--------------------------------------------------------------------------
    |
    | Laravel Cloud injects the REVERB_* group when a managed WebSocket application is attached to the
    | environment. Nothing here sets those values. With no Reverb attached the app falls back to the
     | null broadcaster. HTTP mutations still work, but live delivery waits for Reverb to return.
    |
    */

    'default' => env('BROADCAST_CONNECTION', env('REVERB_APP_KEY') ? 'reverb' : 'null'),

    'connections' => [

        'reverb' => [
            'driver' => 'reverb',
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'app_id' => env('REVERB_APP_ID'),
            'options' => [
                'host' => env('REVERB_HOST'),
                'port' => (int) env('REVERB_PORT', 443),
                'scheme' => env('REVERB_SCHEME', 'https'),
                'useTLS' => env('REVERB_SCHEME', 'https') === 'https',
            ],
            'client_options' => [
                'timeout' => 10,
            ],
        ],

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

];
