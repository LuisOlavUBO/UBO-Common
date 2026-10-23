<?php

use Illuminate\Support\Facades\Http;
use UBO\Common\Tests\TestCase;

pest()->extend(TestCase::class)->in('Support', 'Auth', 'ApiClient', 'Tracing', 'Problems', 'Health', 'SqlServer');

/*
| A fake API-Auth token endpoint answering client credentials requests.
*/
function fakeTokenEndpoint(string $token = 'service-token', int $expiresIn = 3600): array
{
    return ['https://api-auth.test/oauth/token' => Http::response([
        'token_type' => 'Bearer',
        'expires_in' => $expiresIn,
        'access_token' => $token,
    ])];
}
