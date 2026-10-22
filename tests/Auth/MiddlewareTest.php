<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use UBO\Common\Auth\Domain\VerifiedToken;
use UBO\Common\Auth\Exceptions\InsufficientScope;
use UBO\Common\Auth\Testing\TokenFactory;

beforeEach(function () {
    $this->tokens = TokenFactory::fake();

    Route::middleware('ubo.auth:geografia:leer')->get('/protected', fn (Request $request) => [
        'client' => VerifiedToken::from($request)->clientId,
        'scopes' => VerifiedToken::from($request)->scopes,
    ]);

    Route::middleware('ubo.auth:geografia:leer,sistemas:leer')->get('/protected/two', fn () => 'ok');
    Route::middleware('ubo.auth')->get('/protected/any', fn () => 'ok');
});

test('a machine token with the scope gets through and is available in the request', function () {
    $token = $this->tokens->forClient('SIS_SQLSRV', ['geografia:leer']);

    $this->withToken($token)->getJson('/protected')
        ->assertOk()
        ->assertExactJson(['client' => 'SIS_SQLSRV', 'scopes' => ['geografia:leer']]);
});

test('without a token the request is rejected with 401', function () {
    $this->getJson('/protected')->assertUnauthorized();
});

test('invalid tokens are rejected with 401', function (Closure $token) {
    $this->withToken($token($this->tokens))->getJson('/protected')->assertUnauthorized();
})->with([
    'malformed' => [fn () => 'not-a-jwt'],
    'forged (unknown key)' => [fn (TokenFactory $t) => $t->forged('SIS_SQLSRV', ['geografia:leer'])],
    'expired' => [fn (TokenFactory $t) => $t->expired('SIS_SQLSRV', ['geografia:leer'])],
    'tampered signature' => [fn (TokenFactory $t) => substr($t->forClient('SIS_SQLSRV', ['geografia:leer']), 0, -6).'AAAAAA'],
]);

test('a token expires with time', function () {
    $token = $this->tokens->forClient('SIS_SQLSRV', ['geografia:leer'], ttl: 60);

    $this->withToken($token)->getJson('/protected')->assertOk();

    $this->travel(2)->minutes();

    $this->withToken($token)->getJson('/protected')->assertUnauthorized();
});

test('a revoked token is rejected by the hybrid strategy', function () {
    $token = $this->tokens->forClient('SIS_SQLSRV', ['geografia:leer']);
    $this->tokens->revoke($token);

    $this->withToken($token)->getJson('/protected')->assertUnauthorized();
});

test('the jwt strategy does not see revocations (documented trade-off)', function () {
    config(['ubo-auth.strategy' => 'jwt']);
    $token = $this->tokens->forClient('SIS_SQLSRV', ['geografia:leer']);
    $this->tokens->revoke($token);

    $this->withToken($token)->getJson('/protected')->assertOk();
});

test('user tokens are rejected unless allowed', function () {
    $token = $this->tokens->forUser('42', 'portal-client', ['geografia:leer']);

    $this->withToken($token)->getJson('/protected')->assertUnauthorized();

    config(['ubo-auth.allow_user_tokens' => true]);

    $this->withToken($token)->getJson('/protected')->assertOk();
});

test('a machine token whose sub differs only in case from the client id is still a machine token', function (?string $api) {
    // SQL Server returns GUID client ids in upper case; Passport copies that value to "sub".
    config(['ubo-auth.api' => $api]);
    $token = $this->tokens->forUser('01A15F76-9829-7298-85B5-FA625530D051', '01a15f76-9829-7298-85b5-fa625530d051', ['geografia:leer']);

    $this->withToken($token)->getJson('/protected')
        ->assertOk()
        ->assertJsonPath('client', '01a15f76-9829-7298-85b5-fa625530d051');
})->with(['without API name' => [null], 'with API name' => ['api-maestros']]);

test('a missing scope is rejected with 403 and reports the missing scopes', function () {
    $token = $this->tokens->forClient('SIS_SQLSRV', ['geografia:leer']);

    $this->withoutExceptionHandling();

    try {
        $this->withToken($token)->getJson('/protected/two');
        $this->fail('Expected InsufficientScope');
    } catch (InsufficientScope $e) {
        expect($e->scopes())->toBe(['sistemas:leer']);
    }
});

test('a missing scope answers 403', function () {
    $this->withToken($this->tokens->forClient('SIS_SQLSRV', ['homologacion:leer']))
        ->getJson('/protected')
        ->assertForbidden();
});

test('all required scopes are needed', function () {
    $this->withToken($this->tokens->forClient('SIS_SQLSRV', ['geografia:leer', 'sistemas:leer']))
        ->getJson('/protected/two')
        ->assertOk();
});

test('a route without scopes only needs a valid token', function () {
    $this->withToken($this->tokens->forClient('SIS_SQLSRV'))->getJson('/protected/any')->assertOk();
});

test('an API that knows its name rejects machine tokens issued for another API', function () {
    config(['ubo-auth.api' => 'api-maestros']);

    $this->withToken($this->tokens->forClient('SIS_SQLSRV', ['geografia:leer']))
        ->getJson('/protected')->assertOk();
    $this->withToken($this->tokens->forClient('SIS_SQLSRV', ['geografia:leer'], api: 'api-matricula'))
        ->getJson('/protected')->assertUnauthorized();
    $this->withToken($this->tokens->forClient('SIS_SQLSRV', ['geografia:leer'], api: ''))
        ->getJson('/protected')->assertUnauthorized();
});

test('without its name the API accepts machine tokens issued for any API', function () {
    $this->withToken($this->tokens->forClient('SIS_SQLSRV', ['geografia:leer'], api: 'api-matricula'))
        ->getJson('/protected')->assertOk();
});

test('user tokens are not checked against the API name', function () {
    config(['ubo-auth.api' => 'api-maestros', 'ubo-auth.allow_user_tokens' => true]);

    $this->withToken($this->tokens->forUser('42', 'portal-client', ['geografia:leer']))
        ->getJson('/protected')->assertOk();
});
