<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use UBO\Common\Auth\Contracts\TokenValidator;
use UBO\Common\Auth\Exceptions\AuthServerUnavailable;
use UBO\Common\Auth\Exceptions\InvalidToken;

beforeEach(function () {
    config(['ubo-auth.strategy' => 'introspection']);
});

function activeIntrospection(): array
{
    return [
        'active' => true,
        'token_type' => 'Bearer',
        'client_id' => 'SIS_SQLSRV',
        'sub' => null,
        'scope' => 'geografia:leer sistemas:leer',
        'exp' => now()->addHour()->getTimestamp(),
        'user' => null,
    ];
}

test('an active token is accepted and mapped', function () {
    Http::fake([
        ...fakeTokenEndpoint('maestros-own-token'),
        'https://api-auth.test/api/auth/v1/introspect' => Http::response(activeIntrospection()),
    ]);

    $token = app(TokenValidator::class)->validate('opaque-token');

    expect($token->clientId)->toBe('SIS_SQLSRV')
        ->and($token->isMachineToken())->toBeTrue()
        ->and($token->scopes)->toBe(['geografia:leer', 'sistemas:leer']);

    Http::assertSent(fn (Request $r) => $r->url() === 'https://api-auth.test/api/auth/v1/introspect'
        && $r->hasHeader('Authorization', 'Bearer maestros-own-token')
        && $r['token'] === 'opaque-token');

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/auth/oauth/token')
        && $r['grant_type'] === 'client_credentials'
        && $r['scope'] === 'introspect');
});

test('a sub equal to the client id (any case) means a machine token', function (string $sub) {
    Http::fake([
        ...fakeTokenEndpoint(),
        'https://api-auth.test/api/auth/v1/introspect' => Http::response([
            ...activeIntrospection(),
            'client_id' => '01A15F76-9829-7298-85B5-FA625530D051',
            'sub' => $sub,
        ]),
    ]);

    $token = app(TokenValidator::class)->validate('opaque-token');

    expect($token->isMachineToken())->toBeTrue()
        ->and($token->userId)->toBeNull();
})->with([
    'same case' => '01A15F76-9829-7298-85B5-FA625530D051',
    'lower case' => '01a15f76-9829-7298-85b5-fa625530d051',
]);

test('a sub different from the client id is a user', function () {
    Http::fake([
        ...fakeTokenEndpoint(),
        'https://api-auth.test/api/auth/v1/introspect' => Http::response([...activeIntrospection(), 'sub' => '42']),
    ]);

    expect(app(TokenValidator::class)->validate('opaque-token')->userId)->toBe('42');
});

test('an inactive token is rejected', function () {
    Http::fake([
        ...fakeTokenEndpoint(),
        'https://api-auth.test/api/auth/v1/introspect' => Http::response(['active' => false]),
    ]);

    app(TokenValidator::class)->validate('revoked-token');
})->throws(InvalidToken::class);

test('results are cached, so API-Auth is asked once per token', function () {
    Http::fake([
        ...fakeTokenEndpoint(),
        'https://api-auth.test/api/auth/v1/introspect' => Http::response(activeIntrospection()),
    ]);

    app(TokenValidator::class)->validate('opaque-token');
    app(TokenValidator::class)->validate('opaque-token');

    Http::assertSentCount(2); // one service token + one introspection
});

test('a revocation is noticed once the cache entry expires', function () {
    Http::fake([
        ...fakeTokenEndpoint(),
        'https://api-auth.test/api/auth/v1/introspect' => Http::sequence()
            ->push(activeIntrospection())
            ->push(['active' => false]),
    ]);

    app(TokenValidator::class)->validate('opaque-token');

    $this->travel(31)->seconds();

    app(TokenValidator::class)->validate('opaque-token');
})->throws(InvalidToken::class);

test('if its own token is rejected it renews it once', function () {
    Http::fake([
        'https://api-auth.test/api/auth/oauth/token' => Http::sequence()
            ->push(['access_token' => 'old', 'expires_in' => 3600])
            ->push(['access_token' => 'new', 'expires_in' => 3600]),
        'https://api-auth.test/api/auth/v1/introspect' => fn (Request $r) => $r->hasHeader('Authorization', 'Bearer new')
            ? Http::response(activeIntrospection())
            : Http::response(['message' => 'Unauthenticated.'], 401),
    ]);

    expect(app(TokenValidator::class)->validate('opaque-token')->clientId)->toBe('SIS_SQLSRV');
});

test('API-Auth unreachable fails closed with 503', function () {
    Http::fake([
        ...fakeTokenEndpoint(),
        'https://api-auth.test/api/auth/v1/introspect' => Http::failedConnection(),
    ]);

    app(TokenValidator::class)->validate('opaque-token');
})->throws(AuthServerUnavailable::class);

test('missing client credentials fail closed with 503', function () {
    config(['ubo-auth.client_secret' => null]);
    Http::fake();

    app(TokenValidator::class)->validate('opaque-token');
})->throws(AuthServerUnavailable::class);

test('the audience reported by API-Auth is kept, so the middleware can check it', function () {
    Http::fake([
        ...fakeTokenEndpoint(),
        'https://api-auth.test/api/auth/v1/introspect' => Http::response([
            ...activeIntrospection(),
            'aud' => ['SIS_SQLSRV', 'api-maestros'],
        ]),
    ]);

    $token = app(TokenValidator::class)->validate('opaque-token');

    expect($token->audiences)->toBe(['SIS_SQLSRV', 'api-maestros'])
        ->and($token->isFor('api-maestros'))->toBeTrue()
        ->and($token->isFor('api-matricula'))->toBeFalse();
});
