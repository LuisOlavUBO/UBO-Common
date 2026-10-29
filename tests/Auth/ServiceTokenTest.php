<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use UBO\Common\Auth\Contracts\ServiceTokenProvider;
use UBO\Common\Auth\Exceptions\ServiceTokenException;
use UBO\Common\Auth\Infrastructure\ApiAuthClient;

test('it requests a client credentials token and caches it', function () {
    Http::fake(fakeTokenEndpoint('abc'));

    $tokens = app(ServiceTokenProvider::class);

    expect($tokens->token(['geografia:leer']))->toBe('abc')
        ->and($tokens->token(['geografia:leer']))->toBe('abc');

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $r) => $r['client_id'] === 'maestros-client'
        && $r['client_secret'] === 'maestros-secret'
        && $r->isForm());
});

test('scope order does not matter, but different scopes get different tokens', function () {
    Http::fake(fakeTokenEndpoint());
    $tokens = app(ServiceTokenProvider::class);

    $tokens->token(['b:leer', 'a:leer']);
    $tokens->token(['a:leer', 'b:leer']);
    $tokens->token(['a:leer']);

    Http::assertSentCount(2);
});

test('a token for an API asks API-Auth for that API and is cached apart', function () {
    Http::fake(fakeTokenEndpoint());
    $tokens = app(ServiceTokenProvider::class);

    $tokens->token(api: 'api-maestros');
    $tokens->token(api: 'api-maestros');
    $tokens->token(api: 'api-matricula');
    $tokens->forget(api: 'api-matricula');
    $tokens->token(api: 'api-maestros');

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $r) => $r['api'] === 'api-maestros' && ! isset($r['scope']));
    Http::assertSent(fn (Request $r) => $r['api'] === 'api-matricula');
});

test('the hint from API-Auth is part of the error', function () {
    Http::fake(['https://api-auth.test/api/auth/oauth/token' => Http::response([
        'error' => 'invalid_scope',
        'error_description' => 'The requested scope is invalid, unknown, or malformed',
        'hint' => 'Check the `bancos:escribir` scope',
    ], 400)]);

    app(ServiceTokenProvider::class)->token(['bancos:escribir']);
})->throws(ServiceTokenException::class, 'malformed. Check the `bancos:escribir` scope.');

test('it is renewed shortly before it expires', function () {
    Http::fake(['https://api-auth.test/api/auth/oauth/token' => Http::sequence()
        ->push(['access_token' => 'first', 'expires_in' => 3600])
        ->push(['access_token' => 'second', 'expires_in' => 3600])]);
    $tokens = app(ServiceTokenProvider::class);

    expect($tokens->token())->toBe('first');

    $this->travel(3541)->seconds();

    expect($tokens->token())->toBe('second');
});

test('forget drops the cached token', function () {
    Http::fake(['https://api-auth.test/api/auth/oauth/token' => Http::sequence()
        ->push(['access_token' => 'first', 'expires_in' => 3600])
        ->push(['access_token' => 'second', 'expires_in' => 3600])]);
    $tokens = app(ServiceTokenProvider::class);

    $tokens->token(['x']);
    $tokens->forget(['x']);

    expect($tokens->token(['x']))->toBe('second');
});

test('a rejected request explains why', function () {
    Http::fake(['https://api-auth.test/api/auth/oauth/token' => Http::response([
        'error' => 'invalid_scope',
        'error_description' => 'The requested scope is invalid.',
    ], 400)]);

    try {
        app(ServiceTokenProvider::class)->token(['bancos:escribir']);
        $this->fail('Expected ServiceTokenException');
    } catch (ServiceTokenException $e) {
        expect($e->error)->toBe('invalid_scope')
            ->and($e->status)->toBe(400)
            ->and($e->getMessage())->toContain('The requested scope is invalid.');
    }
});

test('without credentials it fails before calling API-Auth', function () {
    config(['ubo-auth.client_id' => null]);
    Http::fake();

    app(ServiceTokenProvider::class)->token();
})->throws(ServiceTokenException::class, 'must be configured');

/*
| Reserve token: the cached token is used while API-Auth does not answer and it has not expired.
*/

function tokenThenFailure(Closure $failure): Closure
{
    $calls = 0;

    return function (Request $request) use ($failure, &$calls) {
        return ++$calls === 1
            ? Http::response(['token_type' => 'Bearer', 'expires_in' => 3600, 'access_token' => 'first-token'])
            : $failure($request);
    };
}

test('a token due for renewal is renewed when API-Auth answers', function () {
    Http::fake(['https://api-auth.test/api/auth/oauth/token' => Http::sequence()
        ->push(['expires_in' => 3600, 'access_token' => 'first-token'])
        ->push(['expires_in' => 3600, 'access_token' => 'second-token'])]);
    $tokens = app(ServiceTokenProvider::class);
    $tokens->token(api: 'api-maestros');

    $this->travel(3570)->seconds(); // 30 s left

    expect($tokens->token(api: 'api-maestros'))->toBe('second-token');
});

test('if API-Auth times out on renewal the token still in force is used', function () {
    Http::fake(['https://api-auth.test/api/auth/oauth/token' => tokenThenFailure(readTimeout())]);
    $tokens = app(ServiceTokenProvider::class);
    $tokens->token(api: 'api-maestros');

    $this->travel(3570)->seconds();

    expect($tokens->token(api: 'api-maestros'))->toBe('first-token');
    Http::assertSentCount(2);
});

test('if API-Auth answers 5xx on renewal the token still in force is used', function () {
    Http::fake(['https://api-auth.test/api/auth/oauth/token' => tokenThenFailure(fn () => Http::response('', 503))]);
    $tokens = app(ServiceTokenProvider::class);
    $tokens->token(api: 'api-maestros');

    $this->travel(3570)->seconds();

    expect($tokens->token(api: 'api-maestros'))->toBe('first-token');
});

test('an expired token is never used, even with API-Auth down', function () {
    Http::fake(['https://api-auth.test/api/auth/oauth/token' => tokenThenFailure(readTimeout())]);
    $tokens = app(ServiceTokenProvider::class);
    $tokens->token(api: 'api-maestros');

    $this->travel(3601)->seconds();

    $tokens->token(api: 'api-maestros');
})->throws(ServiceTokenException::class, 'could not be reached');

test('revoked credentials fail even with a token in force', function () {
    Http::fake(['https://api-auth.test/api/auth/oauth/token' => tokenThenFailure(
        fn () => Http::response(['error' => 'invalid_client', 'error_description' => 'Client authentication failed'], 401)
    )]);
    $tokens = app(ServiceTokenProvider::class);
    $tokens->token(api: 'api-maestros');

    $this->travel(3570)->seconds();

    try {
        $tokens->token(api: 'api-maestros');
        $this->fail('ServiceTokenException was not thrown');
    } catch (ServiceTokenException $e) {
        expect($e->status)->toBe(401)
            ->and($e->error)->toBe('invalid_client');
    }
});

test('with the circuit open and no token in force it fails without calling API-Auth', function () {
    Http::fake();

    for ($i = 0; $i < 5; $i++) {
        app(ApiAuthClient::class)->circuit()->recordFailure();
    }

    expect(fn () => app(ServiceTokenProvider::class)->token(api: 'api-maestros'))
        ->toThrow(ServiceTokenException::class, 'not available');

    Http::assertNothingSent();
});

test('with the circuit open a token in force is still used', function () {
    Http::fake(fakeTokenEndpoint('first-token'));
    $tokens = app(ServiceTokenProvider::class);
    $tokens->token(api: 'api-maestros');

    for ($i = 0; $i < 5; $i++) {
        app(ApiAuthClient::class)->circuit()->recordFailure();
    }

    $this->travel(3570)->seconds();

    expect($tokens->token(api: 'api-maestros'))->toBe('first-token');
});

test('a token cached by an older version (a plain string) is still used', function () {
    Http::fake();
    $key = 'ubo-auth:service-token:'.sha1('maestros-client|api-maestros|');
    cache()->put($key, 'old-format-token', 600);

    expect(app(ServiceTokenProvider::class)->token(api: 'api-maestros'))->toBe('old-format-token');
    Http::assertNothingSent();
});
