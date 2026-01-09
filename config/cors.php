<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    */

    // Paths that CORS should be applied to
    'paths' => ['api/*', 'payment/*'],

    // HTTP methods that are allowed for CORS
    'allowed_methods' => ['*'], // allow all methods

    // Origins that are allowed to access the API
    'allowed_origins' => [
        'http://localhost:5173',
        'http://127.0.0.1:5173',
        // add your production frontend URL here, e.g.
        // 'https://yourdomain.com'
    ],

    // Patterns to match for allowed origins (optional)
    'allowed_origins_patterns' => [],

    // Headers that are allowed in the request
    'allowed_headers' => ['*'], // allow all headers

    // Headers exposed to the browser
    'exposed_headers' => ['Content-Type', 'Cache-Control', 'Authorization', 'X-Requested-With'],

    // Maximum age (in seconds) that preflight requests can be cached
    'max_age' => 0,

    // Whether credentials are supported (cookies, authorization headers)
    'supports_credentials' => false,
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => [
        'http://localhost:5173',
        'http://127.0.0.1:5173',
    ],
    'allowed_headers' => ['*'],
    'supports_credentials' => true,
];
