<?php

use Illuminate\Support\Facades\Http;

test('it reports a healthy connection', function () {
    Http::fake([
        ...fakeTokenEndpoint(),
        'https://api-auth.test/api/v1/auth/public-key' => Http::response(
            file_get_contents(__DIR__.'/../../src/Auth/Testing/keys/test-public.key')
        ),
    ]);

    $this->artisan('ubo:auth:check')
        ->expectsOutputToContain('API-Auth connection is ready.')
        ->assertSuccessful();
});

test('it fails when API-Auth rejects the credentials', function () {
    Http::fake([
        'https://api-auth.test/oauth/token' => Http::response(['error' => 'invalid_client'], 401),
        'https://api-auth.test/api/v1/auth/public-key' => Http::response(
            file_get_contents(__DIR__.'/../../src/Auth/Testing/keys/test-public.key')
        ),
    ]);

    $this->artisan('ubo:auth:check')->assertFailed();
});

test('the hybrid strategy requires client credentials', function () {
    config(['ubo-auth.client_secret' => null]);
    Http::fake([
        'https://api-auth.test/api/v1/auth/public-key' => Http::response(
            file_get_contents(__DIR__.'/../../src/Auth/Testing/keys/test-public.key')
        ),
    ]);

    $this->artisan('ubo:auth:check')->assertFailed();
});
