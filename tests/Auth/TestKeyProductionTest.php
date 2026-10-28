<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use UBO\Common\Auth\Contracts\TokenValidator;
use UBO\Common\Auth\Exceptions\AuthServerUnavailable;
use UBO\Common\Auth\Testing\TestKey;
use UBO\Common\Auth\Testing\TokenFactory;

beforeEach(function () {
    $this->testKey = file_get_contents(TestKey::PUBLIC_KEY_PATH);
    // A token signed with the private test key, as anyone with the package can make one.
    $this->token = (new TokenFactory)->forClient('SIS', ['geografia:leer']);
    $this->reached = false;

    Route::middleware('ubo.auth:geografia:leer')->get('/protected', function () {
        $this->reached = true;

        return 'ok';
    });
});

/*
| The public half of the key TokenFactory::forged() signs with: a real key that is not the test key.
*/
function otherPublicKey(): string
{
    return openssl_pkey_get_details(openssl_pkey_get_private(
        file_get_contents(dirname(TestKey::PUBLIC_KEY_PATH).'/unknown-private.key')
    ))['key'];
}

test('in production the test key fixed in config makes every request a 503', function () {
    $this->app['env'] = 'production';
    config(['ubo-auth.strategy' => 'jwt', 'ubo-auth.public_key' => $this->testKey]);

    $this->withToken($this->token)->getJson('/protected')->assertStatus(503);

    expect($this->reached)->toBeFalse();
});

test('in production the test key downloaded from API-Auth is refused and the reason is logged', function () {
    $this->app['env'] = 'production';
    config(['ubo-auth.strategy' => 'jwt']);
    Http::fake(['https://api-auth.test/api/auth/v1/public-key' => Http::response($this->testKey)]);
    Log::spy();

    expect(fn () => app(TokenValidator::class)->validate($this->token))->toThrow(AuthServerUnavailable::class);

    Log::shouldHaveReceived('critical')->withArgs(fn (string $message) => str_contains($message, 'public test key'));
});

test('in production the test key is refused when a key rotation brings it', function () {
    $this->app['env'] = 'production';
    config(['ubo-auth.strategy' => 'jwt']);
    Http::fake(['https://api-auth.test/api/auth/v1/public-key' => Http::sequence()
        ->push(otherPublicKey())
        ->push($this->testKey)]);

    expect(fn () => app(TokenValidator::class)->validate($this->token))->toThrow(AuthServerUnavailable::class);
});

test('in production the test key is recognised in another format', function (Closure $format) {
    $this->app['env'] = 'production';
    config(['ubo-auth.strategy' => 'jwt', 'ubo-auth.public_key' => $format($this->testKey)]);

    $this->withToken($this->token)->getJson('/protected')->assertStatus(503);
})->with([
    'CRLF line endings' => [fn (string $pem) => str_replace("\n", "\r\n", $pem)],
    'surrounding spaces' => [fn (string $pem) => "  {$pem}   "],
]);

test('in production the hybrid strategy refuses the test key without asking API-Auth', function () {
    $this->app['env'] = 'production';
    config(['ubo-auth.strategy' => 'hybrid', 'ubo-auth.public_key' => $this->testKey]);
    Http::fake();

    expect(fn () => app(TokenValidator::class)->validate($this->token))->toThrow(AuthServerUnavailable::class);

    Http::assertNothingSent();
});

test('TokenFactory::fake() cannot be used in production and leaves the config untouched', function () {
    $this->app['env'] = 'production';
    config(['ubo-auth.public_key' => 'the-real-key']);

    expect(fn () => TokenFactory::fake())->toThrow(LogicException::class, 'cannot be used in production')
        ->and(config('ubo-auth.public_key'))->toBe('the-real-key');
});

test('outside production the test key keeps working', function (string $environment) {
    $this->app['env'] = $environment;
    config(['ubo-auth.strategy' => 'jwt', 'ubo-auth.public_key' => $this->testKey]);

    $this->withToken($this->token)->getJson('/protected')->assertOk();

    expect($this->reached)->toBeTrue();
})->with(['testing', 'local']);
