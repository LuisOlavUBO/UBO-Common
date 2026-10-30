<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use UBO\Common\Auth\Contracts\LocalTokenValidator;
use UBO\Common\Auth\Contracts\UserTokenClient;
use UBO\Common\Auth\Exceptions\AuthServerUnavailable;
use UBO\Common\Auth\Exceptions\InvalidToken;
use UBO\Common\Auth\Exceptions\UserTokenRejected;
use UBO\Common\Auth\Infrastructure\ApiAuthClient;
use UBO\Common\Auth\Testing\TokenFactory;
use UBO\Common\Tracing\Contracts\TraceContext;

const TOKEN_URL = 'https://api-auth.test/api/auth/oauth/token';
const LOGOUT_URL = 'https://api-auth.test/api/auth/v1/logout';

function exchange(): array
{
    return app(UserTokenClient::class)->exchangeCode('login-client', 'login-secret', 'the-code', 'https://portal.test/iam/callback', 'the-verifier');
}

test('a code is exchanged for the access token with the credentials it is given', function () {
    Http::fake([TOKEN_URL => Http::response(['token_type' => 'Bearer', 'expires_in' => 3600, 'access_token' => 'user-token', 'refresh_token' => 'r'])]);

    expect(exchange())->toBe(['access_token' => 'user-token', 'expires_in' => 3600]);

    Http::assertSent(fn (Request $request) => $request->url() === TOKEN_URL
        && $request->method() === 'POST'
        && $request->data() === [
            'grant_type' => 'authorization_code',
            'client_id' => 'login-client',
            'client_secret' => 'login-secret',
            'code' => 'the-code',
            'redirect_uri' => 'https://portal.test/iam/callback',
            'code_verifier' => 'the-verifier',
        ]);
});

test('the login client is not the machine client of ubo-auth', function () {
    Http::fake([TOKEN_URL => Http::response(['expires_in' => 3600, 'access_token' => 'user-token'])]);

    exchange();

    Http::assertSent(fn (Request $request) => $request['client_id'] === 'login-client' && $request['client_id'] !== config('ubo-auth.client_id'));
});

test('a refused exchange carries the status, the error and the description', function () {
    Http::fake([TOKEN_URL => Http::response([
        'error' => 'invalid_grant',
        'error_description' => 'The provided authorization grant is invalid.',
        'hint' => 'Authorization code has been revoked',
    ], 400)]);

    try {
        exchange();
        $this->fail('UserTokenRejected was not thrown');
    } catch (UserTokenRejected $e) {
        expect($e->status)->toBe(400)
            ->and($e->error)->toBe('invalid_grant')
            ->and($e->getMessage())->toContain('The provided authorization grant is invalid')
            ->and($e->getMessage())->toContain('Authorization code has been revoked');
    }
});

test('the message of a refused exchange never includes the secret, the verifier or the code', function () {
    Http::fake([TOKEN_URL => Http::response(['error' => 'invalid_client'], 401)]);

    try {
        exchange();
        $this->fail('UserTokenRejected was not thrown');
    } catch (UserTokenRejected $e) {
        expect($e->getMessage())->not->toContain('login-secret')
            ->and($e->getMessage())->not->toContain('the-verifier')
            ->and($e->getMessage())->not->toContain('the-code');
    }
});

test('an answer without an access token is a refusal', function () {
    Http::fake([TOKEN_URL => Http::response(['expires_in' => 3600])]);

    exchange();
})->throws(UserTokenRejected::class);

test('a live token is revoked with the token as bearer', function () {
    Http::fake([LOGOUT_URL => Http::response(['message' => 'Session closed.'])]);

    app(UserTokenClient::class)->revoke('user-token');

    Http::assertSent(fn (Request $request) => $request->url() === LOGOUT_URL
        && $request->method() === 'POST'
        && $request->header('Authorization') === ['Bearer user-token']);
});

test('a 401 on revocation means it was already revoked', function () {
    Http::fake([LOGOUT_URL => Http::response(['error' => 'NO_AUTENTICADO'], 401)]);

    app(UserTokenClient::class)->revoke('user-token');

    Http::assertSentCount(1);
});

test('with the circuit open nothing is sent, for the exchange or the revocation', function () {
    Http::fake();

    for ($i = 0; $i < 5; $i++) {
        app(ApiAuthClient::class)->circuit()->recordFailure();
    }

    expect(fn () => exchange())->toThrow(AuthServerUnavailable::class)
        ->and(fn () => app(UserTokenClient::class)->revoke('user-token'))->toThrow(AuthServerUnavailable::class);
    Http::assertNothingSent();
});

test('failed exchanges open the circuit that validations and service tokens share', function () {
    Http::fake([TOKEN_URL => connectionRefused()]);

    for ($i = 0; $i < 5; $i++) {
        expect(fn () => exchange())->toThrow(AuthServerUnavailable::class);
    }

    expect(app(ApiAuthClient::class)->circuit()->allowsRequest())->toBeFalse();
});

test('a 5xx is API-Auth being unavailable', function () {
    Http::fake([TOKEN_URL => Http::response('', 503)]);

    exchange();
})->throws(AuthServerUnavailable::class);

test('the exchange carries the trace of the request', function () {
    Http::fake([TOKEN_URL => Http::response(['expires_in' => 3600, 'access_token' => 'user-token'])]);
    $traceId = '0af7651916cd43dd8448eb211c80319c';

    app(TraceContext::class)->begin("00-{$traceId}-b7ad6b7169203331-01");
    exchange();

    Http::assertSent(fn (Request $request) => str_starts_with($request->header('traceparent')[0] ?? '', "00-{$traceId}-"));
});

test('the local validator ignores the strategy and never calls API-Auth', function (string $strategy) {
    config(['ubo-auth.strategy' => $strategy]);
    $tokens = TokenFactory::fake();
    Http::fake();

    $token = app(LocalTokenValidator::class)->validate($tokens->forUser('42', 'login-client', ['geografia:leer'], roles: ['lector']));

    expect($token->userId)->toBe('42')
        ->and($token->roles)->toBe(['lector']);
    Http::assertNothingSent();
})->with(['jwt', 'introspection', 'hybrid']);

test('the local validator rejects a forged token', function () {
    $tokens = TokenFactory::fake();
    Http::fake();

    app(LocalTokenValidator::class)->validate($tokens->forged('login-client', []));
})->throws(InvalidToken::class);
