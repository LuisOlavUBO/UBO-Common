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
    |     UboApi::call('api-maestros', config('services.maestros.url'))->get('/api/maestros/v1/geografia/paises');
    |
    | timeout / connect_timeout: seconds, for each attempt.
    | deadline: seconds for the whole call, attempts, token renewal and waits
    |           included. Never shorter than the timeout of the first attempt.
    |           ->deadline($seconds) sets it for one call.
    | retries: extra attempts after a connection error or a 5xx response on an
    |          idempotent method (GET, HEAD, PUT, DELETE, OPTIONS). POST and PATCH
    |          are only retried when the connection was never established, so a
    |          read timeout never sends them twice. A 401 always gets one extra
    |          attempt with a renewed token, on top of these.
    | retry_delay: milliseconds before the first retry, doubled on each one up to
    |              retry_max_delay, and spread at random between half and all
    |              of it (jitter).
    | circuit_breaker: after "failures" failed attempts in a row (connection
    |                  error, timeout or 5xx), calls to that API fail at once for
    |                  "cooldown" seconds, then a single call probes it.
    |
    */

    'defaults' => [
        'timeout' => 10,
        'connect_timeout' => 5,
        'deadline' => 30,
        'retries' => 2,
        'retry_delay' => 200,
        'retry_max_delay' => 2000,
        'headers' => [],
        'circuit_breaker' => [
            'enabled' => true,
            'failures' => 5,
            'cooldown' => 30,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Values per API
    |--------------------------------------------------------------------------
    |
    | Keyed by the API's name in API-Auth. Each key set here replaces the one
    | in "defaults" for that API only; the rest come from "defaults".
    |
    |     'api-reportes' => ['timeout' => 30, 'deadline' => 45],
    |     'api-legado' => ['circuit_breaker' => ['enabled' => false]],
    |
    */

    'apis' => [],

    /*
    | Cache store holding the circuits' state (empty: the application's
    | default store). Use a shared store (redis, database) so every worker and
    | server sees the same circuit.
    */
    'cache_store' => env('UBO_APIS_CACHE_STORE'),

];
