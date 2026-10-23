<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Response header
    |--------------------------------------------------------------------------
    |
    | Every response carries the trace id in this header, so a caller can quote
    | it when reporting a problem and it can be searched in the logs of every
    | service in the chain. Empty: the header is not sent.
    |
    */

    'response_header' => env('UBO_TRACING_RESPONSE_HEADER', 'X-Trace-Id'),

    /*
    |--------------------------------------------------------------------------
    | Continue the caller's trace
    |--------------------------------------------------------------------------
    |
    | true: a valid "traceparent" header from the caller is continued, so the
    | whole chain shares one trace id. false: every request starts a new trace
    | (for an application exposed to callers outside the UBO).
    |
    */

    'trust_incoming' => (bool) env('UBO_TRACING_TRUST_INCOMING', true),

];
