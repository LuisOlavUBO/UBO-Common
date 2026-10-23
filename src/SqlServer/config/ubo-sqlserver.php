<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Date format
    |--------------------------------------------------------------------------
    |
    | Sent as "SET DATEFORMAT <format>" when each sqlsrv connection opens. ymd
    | matches the "Y-m-d H:i:s" dates Laravel sends, whatever the language of
    | the SQL Server login. One of: mdy, dmy, ymd, ydm, myd, dym.
    |
    */

    'date_format' => env('UBO_SQLSERVER_DATE_FORMAT', 'ymd'),

];
