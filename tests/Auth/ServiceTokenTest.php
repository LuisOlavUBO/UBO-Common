<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use UBO\Common\Auth\Contracts\ServiceTokenProvider;
use UBO\Common\Auth\Exceptions\ServiceTokenException;

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

test('it is renewed shortly before it expires', function () {
    Http::fake(['https://api-auth.test/oauth/token' => Http::sequence()
        ->push(['access_token' => 'first', 'expires_in' => 3600])
        ->push(['access_token' => 'second', 'expires_in' => 3600])]);
    $tokens = app(ServiceTokenProvider::class);

    expect($tokens->token())->toBe('first');

    $this->travel(3541)->seconds();

    expect($tokens->token())->toBe('second');
});

test('forget drops the cached token', function () {
    Http::fake(['https://api-auth.test/oauth/token' => Http::sequence()
        ->push(['access_token' => 'first', 'expires_in' => 3600])
        ->push(['access_token' => 'second', 'expires_in' => 3600])]);
    $tokens = app(ServiceTokenProvider::class);

    $tokens->token(['x']);
    $tokens->forget(['x']);

    expect($tokens->token(['x']))->toBe('second');
});

test('a rejected request explains why', function () {
    Http::fake(['https://api-auth.test/oauth/token' => Http::response([
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
