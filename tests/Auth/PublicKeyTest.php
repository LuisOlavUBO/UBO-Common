<?php

use Illuminate\Support\Facades\Http;
use UBO\Common\Auth\Contracts\PublicKeyProvider;
use UBO\Common\Auth\Contracts\TokenValidator;
use UBO\Common\Auth\Exceptions\AuthServerUnavailable;
use UBO\Common\Auth\Exceptions\InvalidToken;
use UBO\Common\Auth\Infrastructure\HttpPublicKeyProvider;
use UBO\Common\Auth\Infrastructure\StaticPublicKeyProvider;
use UBO\Common\Auth\Testing\TokenFactory;

beforeEach(function () {
    config(['ubo-auth.strategy' => 'jwt']);
    $this->testKey = file_get_contents(__DIR__.'/../../src/Auth/Testing/keys/test-public.key');
});

test('without a configured key it is downloaded from API-Auth and cached', function () {
    Http::fake(['https://api-auth.test/api/auth/v1/public-key' => Http::response($this->testKey)]);

    expect(app(PublicKeyProvider::class))->toBeInstanceOf(HttpPublicKeyProvider::class)
        ->and(app(PublicKeyProvider::class)->get())->toBe($this->testKey)
        ->and(app(PublicKeyProvider::class)->get())->toBe($this->testKey);

    Http::assertSentCount(1);
});

test('a key fixed in config is used without network calls', function () {
    config(['ubo-auth.public_key' => $this->testKey]);
    Http::fake();

    expect(app(PublicKeyProvider::class))->toBeInstanceOf(StaticPublicKeyProvider::class)
        ->and(app(PublicKeyProvider::class)->get())->toBe($this->testKey);

    Http::assertNothingSent();
});

test('a valid token is accepted with the downloaded key', function () {
    Http::fake(['https://api-auth.test/api/auth/v1/public-key' => Http::response($this->testKey)]);
    $token = (new TokenFactory)->forClient('SIS_SQLSRV', ['geografia:leer']);

    expect(app(TokenValidator::class)->validate($token)->clientId)->toBe('SIS_SQLSRV');
});

test('after a key rotation the new key is downloaded once', function () {
    $previousKey = openssl_pkey_get_details(openssl_pkey_get_private(
        file_get_contents(__DIR__.'/../../src/Auth/Testing/keys/unknown-private.key')
    ))['key'];

    Http::fake(['https://api-auth.test/api/auth/v1/public-key' => Http::sequence()
        ->push($previousKey)
        ->push($this->testKey)]);

    $token = (new TokenFactory)->forClient('SIS_SQLSRV');

    expect(app(TokenValidator::class)->validate($token)->clientId)->toBe('SIS_SQLSRV');

    Http::assertSentCount(2);
});

test('forged tokens cannot make it download the key more than once per minute', function () {
    Http::fake(['https://api-auth.test/api/auth/v1/public-key' => Http::response($this->testKey)]);
    $forged = (new TokenFactory)->forged('SIS_SQLSRV');

    foreach (range(1, 5) as $_) {
        try {
            app(TokenValidator::class)->validate($forged);
        } catch (InvalidToken) {
        }
    }

    Http::assertSentCount(2); // first download + one refresh
});

test('a forged token while API-Auth is down is an invalid token, not an unavailable server', function () {
    Http::fake(['https://api-auth.test/api/auth/v1/public-key' => Http::sequence()
        ->push($this->testKey)
        ->pushFailedConnection()]);

    app(TokenValidator::class)->validate((new TokenFactory)->forClient('SIS_SQLSRV'));

    app(TokenValidator::class)->validate((new TokenFactory)->forged('SIS_SQLSRV'));
})->throws(InvalidToken::class);

test('a failed refresh keeps the cached key, so valid tokens keep working while API-Auth is down', function () {
    Http::fake(['https://api-auth.test/api/auth/v1/public-key' => Http::sequence()
        ->push($this->testKey)
        ->push('', 500)
        ->pushFailedConnection()]);
    $tokens = new TokenFactory;

    expect(app(TokenValidator::class)->validate($tokens->forClient('SIS_SQLSRV'))->clientId)->toBe('SIS_SQLSRV');

    try {
        app(TokenValidator::class)->validate($tokens->forged('SIS_SQLSRV'));
    } catch (InvalidToken) {
    }

    expect(app(TokenValidator::class)->validate($tokens->forClient('SIS_SQLSRV'))->clientId)->toBe('SIS_SQLSRV')
        ->and(app(PublicKeyProvider::class)->get())->toBe($this->testKey);

    Http::assertSentCount(2); // first download + the failed refresh
});

test('API-Auth being down fails closed with 503', function () {
    Http::fake(['https://api-auth.test/*' => Http::response('', 500)]);

    app(TokenValidator::class)->validate((new TokenFactory)->forClient('SIS_SQLSRV'));
})->throws(AuthServerUnavailable::class);
