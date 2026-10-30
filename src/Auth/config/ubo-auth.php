<?php

return [

    /*
    |--------------------------------------------------------------------------
    | API-Auth
    |--------------------------------------------------------------------------
    |
    | Base URL of API-Auth, the only service that issues tokens. The endpoint
    | paths are fixed by the package to API-Auth's routes (/api/auth/oauth/token,
    | /api/auth/v1/introspect, /api/auth/v1/public-key, /api/auth/v1/logout), so a published copy of
    | this file never freezes them. Set a variable only to replace one; empty
    | means the package's route. An "endpoints" array from an older version of
    | this file is ignored.
    |
    */

    'url' => env('UBO_AUTH_URL'),

    'endpoint_overrides' => [
        'token' => env('UBO_AUTH_ENDPOINT_TOKEN'),
        'introspect' => env('UBO_AUTH_ENDPOINT_INTROSPECT'),
        'public_key' => env('UBO_AUTH_ENDPOINT_PUBLIC_KEY'),
        'logout' => env('UBO_AUTH_ENDPOINT_LOGOUT'),
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
    | Name of THIS API in API-Auth (e.g. "api-maestros"). When set, machine
    | tokens must name it in their "aud" claim, so a token issued for another
    | API is rejected even if it carries a scope with the same name. Leave it
    | empty in applications that only call other APIs.
    */
    'api' => env('UBO_AUTH_API'),

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
    | Timeout (seconds) for calls to API-Auth, and for establishing the connection
    | (never longer than the timeout). A short connect timeout fails fast when
    | API-Auth's host does not accept connections.
    */
    'timeout' => (int) env('UBO_AUTH_TIMEOUT', 5),

    'connect_timeout' => (int) env('UBO_AUTH_CONNECT_TIMEOUT', 2),

    /*
    |--------------------------------------------------------------------------
    | Circuit breaker
    |--------------------------------------------------------------------------
    |
    | After "failures" calls to API-Auth in a row fail (connection error,
    | timeout or 5xx), no call is sent for "cooldown" seconds: token validation
    | answers 503 at once instead of waiting. Then a single call probes whether
    | API-Auth is back. The state lives in the cache store above: use a shared
    | store (redis, database) so every worker and server sees the same circuit.
    |
    */

    'circuit_breaker' => [
        'failures' => (int) env('UBO_AUTH_CIRCUIT_FAILURES', 5),
        'cooldown' => (int) env('UBO_AUTH_CIRCUIT_COOLDOWN', 30),
    ],

    /*
    | Degraded mode (hybrid strategy only, off by default). When introspection
    | cannot reach API-Auth, accept the tokens that passed the local check
    | (signature and expiry) instead of answering 503. While API-Auth is down,
    | revoked tokens are accepted until they expire.
    */
    'degraded_mode' => (bool) env('UBO_AUTH_DEGRADED_MODE', false),

];
