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
    */

    'modules' => [
        'auth' => (bool) env('UBO_COMMON_AUTH', true),
        'api-client' => (bool) env('UBO_COMMON_API_CLIENT', true),
    ],

];
