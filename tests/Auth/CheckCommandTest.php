<?php

use Illuminate\Support\Facades\Http;

test('it reports a healthy connection', function () {
    Http::fake([
        ...fakeTokenEndpoint(),
        'https://api-auth.test/api/auth/v1/public-key' => Http::response(
            file_get_contents(__DIR__.'/../../src/Auth/Testing/keys/test-public.key')
        ),
    ]);

    $this->artisan('ubo:auth:check')
        ->expectsOutputToContain('API-Auth connection is ready.')
        ->assertSuccessful();
});

test('in production it fails when the public key is the test key', function () {
    $this->app['env'] = 'production';
    Http::fake([
        ...fakeTokenEndpoint(),
        'https://api-auth.test/api/auth/v1/public-key' => Http::response(
            file_get_contents(__DIR__.'/../../src/Auth/Testing/keys/test-public.key')
        ),
    ]);

    $this->artisan('ubo:auth:check')
        ->expectsOutputToContain('public test key')
        ->expectsOutputToContain('API-Auth connection has problems.')
        ->assertFailed();
});

test('it fails when API-Auth rejects the credentials', function () {
    Http::fake([
        'https://api-auth.test/api/auth/oauth/token' => Http::response(['error' => 'invalid_client'], 401),
        'https://api-auth.test/api/auth/v1/public-key' => Http::response(
            file_get_contents(__DIR__.'/../../src/Auth/Testing/keys/test-public.key')
        ),
    ]);

    $this->artisan('ubo:auth:check')->assertFailed();
});

test('the hybrid strategy requires client credentials', function () {
    config(['ubo-auth.client_secret' => null]);
    Http::fake([
        'https://api-auth.test/api/auth/v1/public-key' => Http::response(
            file_get_contents(__DIR__.'/../../src/Auth/Testing/keys/test-public.key')
        ),
    ]);

    $this->artisan('ubo:auth:check')->assertFailed();
});
