<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Defaults for every API
    |--------------------------------------------------------------------------
    |
    | The API itself is given on each call, with its name in API-Auth and its
    | URL (kept in the calling application's own config):
    |
    |     UboApi::call('api-maestros', config('services.maestros.url'))->get('/api/v1/geografia/paises');
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

];
