<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Modules
    |--------------------------------------------------------------------------
    |
    | Each UBO.Common module can be switched on or off per application, so an
    | app installs the package once and only loads what it uses. Every module
    | keeps its own configuration file (config/ubo-<module>.php).
    |
    | sqlserver is off by default: it changes how every sqlsrv connection
    | behaves, so each application turns it on explicitly. So is login: it
    | registers routes, a guard and Gate rules for logging in with IAM.
    |
    */

    'modules' => [
        'auth' => (bool) env('UBO_COMMON_AUTH', true),
        'api-client' => (bool) env('UBO_COMMON_API_CLIENT', true),
        'tracing' => (bool) env('UBO_COMMON_TRACING', true),
        'problems' => (bool) env('UBO_COMMON_PROBLEMS', true),
        'health' => (bool) env('UBO_COMMON_HEALTH', true),
        'sqlserver' => (bool) env('UBO_COMMON_SQLSERVER', false),
        'login' => (bool) env('UBO_COMMON_LOGIN', false),
    ],

];
