<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use UBO\Common\Auth\Contracts\ServiceTokenProvider;
use UBO\Common\Auth\Contracts\TokenValidator;

test('the endpoints of a published config from an older version are ignored', function () {
    config(['ubo-auth.endpoints.token' => '/oauth/token']);
    Http::fake(fakeTokenEndpoint('abc'));

    expect(app(ServiceTokenProvider::class)->token())->toBe('abc');

    Http::assertSent(fn (Request $r) => $r->url() === 'https://api-auth.test/api/auth/oauth/token');
    Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/oauth/token') && ! str_contains($r->url(), '/api/auth/'));
});

test('a route can be replaced through its variable', function () {
    config([
        'ubo-auth.strategy' => 'introspection',
        'ubo-auth.endpoint_overrides.introspect' => '/auth/v2/introspect',
    ]);
    Http::fake([
        ...fakeTokenEndpoint(),
        'https://api-auth.test/auth/v2/introspect' => Http::response([
            'active' => true,
            'client_id' => 'SIS_SQLSRV',
            'scope' => 'geografia:leer',
            'exp' => now()->addHour()->getTimestamp(),
        ]),
    ]);

    expect(app(TokenValidator::class)->validate('any-token')->clientId)->toBe('SIS_SQLSRV');

    Http::assertSent(fn (Request $r) => $r->url() === 'https://api-auth.test/auth/v2/introspect');
});

test('an empty route variable uses the package route', function () {
    config(['ubo-auth.endpoint_overrides.token' => '']);
    Http::fake(fakeTokenEndpoint('abc'));

    expect(app(ServiceTokenProvider::class)->token())->toBe('abc');

    Http::assertSent(fn (Request $r) => $r->url() === 'https://api-auth.test/api/auth/oauth/token');
});
