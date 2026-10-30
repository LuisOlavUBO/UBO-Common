<?php

use Illuminate\Support\Facades\Http;
use UBO\Common\Auth\Infrastructure\ApiAuthClient;

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

test('with the circuit open it still reaches API-Auth and leaves the circuit open', function () {
    Http::fake([
        ...fakeTokenEndpoint(),
        'https://api-auth.test/api/auth/v1/public-key' => Http::response(
            file_get_contents(__DIR__.'/../../src/Auth/Testing/keys/test-public.key')
        ),
    ]);
    $circuit = app(ApiAuthClient::class)->circuit();

    for ($i = 0; $i < 5; $i++) {
        $circuit->recordFailure();
    }

    $this->artisan('ubo:auth:check')
        ->expectsOutputToContain('open')
        ->expectsOutputToContain('API-Auth connection is ready.')
        ->assertSuccessful();

    expect(app(ApiAuthClient::class)->circuit()->isOpen())->toBeTrue();
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/public-key'));
});

test('it shows whether the degraded mode is on', function () {
    config(['ubo-auth.degraded_mode' => true]);
    Http::fake([
        ...fakeTokenEndpoint(),
        'https://api-auth.test/api/auth/v1/public-key' => Http::response(
            file_get_contents(__DIR__.'/../../src/Auth/Testing/keys/test-public.key')
        ),
    ]);

    $this->artisan('ubo:auth:check')
        ->expectsOutputToContain('Degraded mode')
        ->assertSuccessful();
});
