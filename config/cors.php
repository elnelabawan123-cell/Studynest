<?php

$configuredOrigins = array_filter(array_map(
    'trim',
    preg_split('/\s*,\s*/', env(
        'CORS_ALLOWED_ORIGINS',
        'http://localhost:3000,http://127.0.0.1:3000,http://localhost:5173,http://127.0.0.1:5173'
    ))
));

return [
    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS" in your application. This will allow your API to accept
    | requests from your React frontend and other trusted origins.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_unique(array_merge(
        $configuredOrigins,
        [
            'https://web-admin-58800.web.app',
            'https://web-admin-58800.firebaseapp.com',
        ]
    ))),

    // Flutter's web dev server picks a random port each run, so the fixed
    // origin list above can't keep up. Allow any localhost/127.0.0.1 port
    // for local development instead.
    'allowed_origins_patterns' => [
        '#^https?://(localhost|127\.0\.0\.1)(:\d+)?$#',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,
];
