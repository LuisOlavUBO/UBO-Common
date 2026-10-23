<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    |
    | ProblemRenderer only answers requests whose path matches one of these
    | patterns (Request::is() syntax, comma separated in the .env). Other
    | requests keep Laravel's default error pages.
    |
    */

    'routes' => env('UBO_PROBLEMS_ROUTES', 'api/*'),

];
