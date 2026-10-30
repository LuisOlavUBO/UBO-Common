<?php

return [

    /*
    |--------------------------------------------------------------------------
    | IAM (API-Auth) as seen by the browser
    |--------------------------------------------------------------------------
    |
    | The person's browser is sent to IAM's login and logout pages, so this is the
    | public address of API-Auth (e.g. https://iam.ubo.cl). Calls from this server
    | to API-Auth (code exchange, revocation) use ubo-auth.url, the internal one. When
    | empty, ubo-auth.url is used for both. The routes are fixed by the package:
    | /api/auth/oauth/authorize and /api/auth/logout.
    |
    */

    'public_url' => env('UBO_LOGIN_PUBLIC_URL'),

    /*
    |--------------------------------------------------------------------------
    | Login client of THIS application
    |--------------------------------------------------------------------------
    |
    | Created in API-Auth for the application with:
    |
    |   php artisan oauth:client portal-web --application=portal --type=authorization-code --redirect=https://portal.ubo.cl/iam/callback
    |
    | It is not the machine-to-machine client of ubo-auth: that one is optional here.
    |
    */

    'client_id' => env('UBO_LOGIN_CLIENT_ID'),

    'client_secret' => env('UBO_LOGIN_CLIENT_SECRET'),

    /*
    | The redirect URI registered in API-Auth for the client. When empty it is the callback
    | route under APP_URL (never built from the request's Host header).
    */
    'redirect_uri' => env('UBO_LOGIN_REDIRECT_URI'),

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    |
    | <prefix>/login, <prefix>/callback and <prefix>/logout (named ubo.login,
    | ubo.login.callback and ubo.logout), in the "web" middleware group.
    |
    */

    'route_prefix' => env('UBO_LOGIN_ROUTE_PREFIX', 'iam'),

    // Where a person lands when there is no page to go back to, and where IAM sends them after logout.
    'home' => env('UBO_LOGIN_HOME', '/'),

    'logout_return' => env('UBO_LOGIN_LOGOUT_RETURN'),

    // "login" asks for the password on every login, even when the person has an IAM session.
    'prompt' => env('UBO_LOGIN_PROMPT'),

    // Seconds a started login may take before its state expires.
    'state_ttl' => (int) env('UBO_LOGIN_STATE_TTL', 600),

];
