<?php

return [

    'paths' => [
        'api/*',
        'sanctum/csrf-cookie',
    ],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'http://localhost:5173',
        'http://localhost:5174',           // ✅ ADD THIS - Your current dev port
        'http://127.0.0.1:5173',
        'http://127.0.0.1:5174',           // ✅ ADD THIS - Alternative localhost
        'https://study.learner-teach.online',
        'https://smart-registration-frontend.vercel.app'
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [
        'Authorization',
        'Content-Type',
        'X-Requested-With',
    ],

    'max_age' => 0,

    // 🔑 IMPORTANT (you are using cookies / auth)
    'supports_credentials' => true,
];