<?php

use Illuminate\Support\Str;

return [
    'driver' => env('SESSION_DRIVER', 'database'), // ✅ Use database for sessions

    'lifetime' => env('SESSION_LIFETIME', 120),

    'expire_on_close' => false,

    'encrypt' => false,

    'files' => storage_path('framework/sessions'),

    'connection' => env('SESSION_CONNECTION'),

    'table' => env('SESSION_TABLE', 'sessions'),

    'store' => env('SESSION_STORE'),

    'lottery' => [2, 100],

    'cookie' => env(
        'SESSION_COOKIE',
        Str::slug(env('APP_NAME', 'laravel'), '_').'_session'
    ),

    'path' => '/',

    // 🔧 PRODUCTION: Change to your production domain
    'domain' => env('SESSION_DOMAIN'),

    // 🔧 PRODUCTION: Set to true (requires HTTPS)
    'secure' => env('SESSION_SECURE_COOKIE', false),

    'http_only' => true, // ✅ Always true for security

    // 🔧 PRODUCTION: Change to 'none' if frontend on different domain
    'same_site' => env('SESSION_SAME_SITE', 'lax'),

    'partitioned' => false,
];