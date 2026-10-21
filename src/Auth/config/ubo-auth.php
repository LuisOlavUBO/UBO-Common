<?php

return [

    /*
    |--------------------------------------------------------------------------
    | API-Auth
    |--------------------------------------------------------------------------
    |
    | Base URL of API-Auth, the only service that issues tokens. The endpoint
    | paths match API-Auth's routes and rarely need to change.
    |
    */

    'url' => env('UBO_AUTH_URL'),

    'endpoints' => [
        'token' => '/oauth/token',
        'introspect' => '/api/v1/auth/introspect',
        'public_key' => '/api/v1/auth/public-key',
    ],

    /*
    |--------------------------------------------------------------------------
    | Client credentials of THIS application
    |--------------------------------------------------------------------------
    |
    | Created in API-Auth with "php artisan passport:client --client". Used to
    | call introspection (scope "introspect") and, through the api-client
    | module, to call other UBO APIs.
    |
    */

    'client_id' => env('UBO_AUTH_CLIENT_ID'),

    'client_secret' => env('UBO_AUTH_CLIENT_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Validation strategy (used by the "ubo.auth" middleware)
    |--------------------------------------------------------------------------
    |
    | jwt:           signature + expiry checked locally. No network calls, but a
    |                revoked token stays usable until it expires.
    | introspection: every token is checked against API-Auth (cached).
    | hybrid:        local check first, then introspection (cached). Rejects
    |                forged tokens cheaply and honours revocation.
    |
    */

    'strategy' => env('UBO_AUTH_STRATEGY', 'hybrid'),

    /*
    | Only machine-to-machine (client credentials) tokens are accepted by
    | default. Set to true to also accept tokens issued to users.
    */
    'allow_user_tokens' => (bool) env('UBO_AUTH_ALLOW_USER_TOKENS', false),

    /*
    | API-Auth public key (PEM text or path to a .pem/.key file). When empty it
    | is downloaded from API-Auth and cached.
    */
    'public_key' => env('UBO_AUTH_PUBLIC_KEY'),

    /*
    | Seconds of clock skew tolerated when checking exp / nbf / iat.
    */
    'leeway' => (int) env('UBO_AUTH_LEEWAY', 10),

    /*
    |--------------------------------------------------------------------------
    | Cache
    |--------------------------------------------------------------------------
    */

    'cache' => [
        // Cache store name (null = the application's default store).
        'store' => env('UBO_AUTH_CACHE_STORE'),
        'prefix' => 'ubo-auth:',
        // Seconds the downloaded public key is kept.
        'public_key_ttl' => (int) env('UBO_AUTH_PUBLIC_KEY_TTL', 86400),
        // Seconds an introspection result is kept (maximum revocation delay).
        'introspection_ttl' => (int) env('UBO_AUTH_INTROSPECTION_TTL', 30),
        // Seconds before expiry when a cached service token is renewed.
        'service_token_margin' => 60,
    ],

    /*
    | Timeout (seconds) for calls to API-Auth.
    */
    'timeout' => (int) env('UBO_AUTH_TIMEOUT', 5),

];
