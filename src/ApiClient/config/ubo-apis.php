<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Defaults for every API
    |--------------------------------------------------------------------------
    |
    | timeout / connect_timeout: seconds.
    | retries: extra attempts after a connection error or a 5xx response on an
    |          idempotent method (GET, HEAD, PUT, DELETE, OPTIONS). A 401 always
    |          gets one extra attempt with a renewed token, on top of these.
    | retry_delay: milliseconds, multiplied by the attempt number.
    |
    */

    'defaults' => [
        'timeout' => 10,
        'connect_timeout' => 5,
        'retries' => 2,
        'retry_delay' => 200,
        'headers' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | API catalog
    |--------------------------------------------------------------------------
    |
    | Logical name => settings. The name is what developers use:
    |
    |     UboApi::call('maestros')->get('/api/v1/geografia/paises');
    |
    | "scopes" are requested to API-Auth for this API (array or space separated).
    | Any key from "defaults" can be overridden per API.
    |
    */

    'apis' => [

        'maestros' => [
            'url' => env('UBO_API_MAESTROS_URL'),
            'scopes' => env('UBO_API_MAESTROS_SCOPES', 'geografia:leer sistemas:leer homologacion:leer'),
        ],

    ],

];
