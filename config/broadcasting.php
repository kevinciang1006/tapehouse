<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Broadcaster
    |--------------------------------------------------------------------------
    |
    | This option controls the default broadcaster that will be used by the
    | framework when an event needs to be broadcast. You may set this to
    | any of the connections defined in the "connections" array below.
    |
    | Supported: "reverb", "pusher", "ably", "redis", "log", "null"
    |
    */

    'default' => env('BROADCAST_CONNECTION', 'reverb'),

    /*
    |--------------------------------------------------------------------------
    | Broadcast Connections
    |--------------------------------------------------------------------------
    |
    | Here you may define all of the broadcast connections that will be used
    | to broadcast events to other systems or over WebSockets. Samples of
    | each available type of connection are provided inside this array.
    |
    */

    'connections' => [

        'reverb' => [
            'driver' => 'reverb',
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'app_id' => env('REVERB_APP_ID'),
            'options' => [
                // 'host' is read by the SERVER: the queue worker's
                // BroadcastEvent job posts each frame straight to Reverb's
                // HTTP API (PusherBroadcaster, since Reverb speaks the
                // Pusher protocol) using exactly this value. It has to be
                // an address reachable from wherever THAT process runs —
                // the `reverb` compose service name under Compose, or
                // Reverb's own loopback port in the single-container
                // production image (see docker/nginx/prod.conf).
                'host' => env('REVERB_HOST'),
                'port' => env('REVERB_PORT', 443),
                'scheme' => env('REVERB_SCHEME', 'https'),
                'useTLS' => env('REVERB_SCHEME', 'https') === 'https',
                // 'client_host' is read by the BROWSER, via the
                // `reverb-host` meta tag in layouts/app.blade.php — a
                // completely different audience that needs a completely
                // different address. Under Compose, REVERB_HOST is set to
                // the `reverb` service name so the queue worker (running
                // inside the compose network) can resolve it; a browser
                // on the developer's own machine cannot resolve that same
                // name at all, so reusing 'host' for both was the bug —
                // the tape looked fully healthy server-side while the
                // browser's WebSocket handshake failed silently. Falls
                // back to REVERB_HOST so single-audience setups (this
                // project's non-Docker host dev, where both the PHP
                // process and the browser reach Reverb via 127.0.0.1)
                // don't need to set a second variable at all.
                'client_host' => env('REVERB_CLIENT_HOST', env('REVERB_HOST')),
            ],
            'client_options' => [
                // Guzzle client options: https://docs.guzzlephp.org/en/stable/request-options.html
            ],
        ],

        'pusher' => [
            'driver' => 'pusher',
            'key' => env('PUSHER_APP_KEY'),
            'secret' => env('PUSHER_APP_SECRET'),
            'app_id' => env('PUSHER_APP_ID'),
            'options' => [
                'cluster' => env('PUSHER_APP_CLUSTER'),
                'host' => env('PUSHER_HOST') ?: 'api-'.env('PUSHER_APP_CLUSTER', 'mt1').'.pusher.com',
                'port' => env('PUSHER_PORT', 443),
                'scheme' => env('PUSHER_SCHEME', 'https'),
                'encrypted' => true,
                'useTLS' => env('PUSHER_SCHEME', 'https') === 'https',
            ],
            'client_options' => [
                // Guzzle client options: https://docs.guzzlephp.org/en/stable/request-options.html
            ],
        ],

        'ably' => [
            'driver' => 'ably',
            'key' => env('ABLY_KEY'),
        ],

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

];
