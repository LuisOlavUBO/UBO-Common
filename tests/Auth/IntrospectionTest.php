<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use UBO\Common\Auth\Contracts\TokenValidator;
use UBO\Common\Auth\Exceptions\AuthServerUnavailable;
use UBO\Common\Auth\Exceptions\InvalidToken;
use UBO\Common\Auth\Infrastructure\ApiAuthClient;
use UBO\Common\Auth\Testing\TokenFactory;

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

/*
| Circuit breaker towards API-Auth.
*/

function failIntrospections(int $times): void
{
    for ($i = 0; $i < $times; $i++) {
        try {
            app(TokenValidator::class)->validate("down-{$i}");
        } catch (AuthServerUnavailable) {
        }
    }
}

test('after five failures in a row API-Auth is not called and the answer is 503 at once', function () {
    Http::fake([
        ...fakeTokenEndpoint(),
        'https://api-auth.test/api/auth/v1/introspect' => readTimeout(),
    ]);
    failIntrospections(5);
    $sent = count(Http::recorded());

    expect(fn () => app(TokenValidator::class)->validate('another-token'))
        ->toThrow(AuthServerUnavailable::class, 'circuit open');

    expect(Http::recorded())->toHaveCount($sent);
});

test('after the cooldown a successful probe closes the circuit', function () {
    $calls = 0;
    Http::fake([
        ...fakeTokenEndpoint(),
        'https://api-auth.test/api/auth/v1/introspect' => function (Request $request) use (&$calls) {
            return ++$calls <= 5 ? readTimeout()($request) : Http::response(activeIntrospection());
        },
    ]);
    failIntrospections(5);

    $this->travel(31)->seconds();

    expect(app(TokenValidator::class)->validate('probe-token')->clientId)->toBe('SIS_SQLSRV')
        ->and(app(TokenValidator::class)->validate('next-token')->clientId)->toBe('SIS_SQLSRV')
        ->and($calls)->toBe(7);
});

test('after the cooldown a failed probe opens the circuit for another cooldown', function () {
    Http::fake([
        ...fakeTokenEndpoint(),
        'https://api-auth.test/api/auth/v1/introspect' => readTimeout(),
    ]);
    failIntrospections(5);
    $this->travel(31)->seconds();
    failIntrospections(1);
    $sent = count(Http::recorded());

    expect(fn () => app(TokenValidator::class)->validate('another-token'))
        ->toThrow(AuthServerUnavailable::class, 'circuit open');

    expect(Http::recorded())->toHaveCount($sent);
});

test('client errors from API-Auth never open the circuit', function () {
    Http::fake([
        ...fakeTokenEndpoint(),
        'https://api-auth.test/api/auth/v1/introspect' => Http::response(['error' => 'invalid_request'], 400),
    ]);
    failIntrospections(7);

    Http::assertSentCount(8); // one own token, seven introspections
});

test('with the circuit open the jwt strategy still accepts valid tokens', function () {
    $tokens = TokenFactory::fake();
    config(['ubo-auth.strategy' => 'jwt']);
    Http::fake();

    for ($i = 0; $i < 5; $i++) {
        app(ApiAuthClient::class)->circuit()->recordFailure();
    }

    expect(app(ApiAuthClient::class)->circuit()->isOpen())->toBeTrue()->and(app(TokenValidator::class)->validate($tokens->forClient('SIS', ['geografia:leer']))->clientId)->toBe('SIS');
});

test('a host that refuses connections fails without waiting for the timeout', function () {
    Http::fake([
        ...fakeTokenEndpoint(),
        'https://api-auth.test/api/auth/v1/introspect' => connectTimeout(),
    ]);

    expect(fn () => app(TokenValidator::class)->validate('opaque-token'))
        ->toThrow(AuthServerUnavailable::class, 'could not be reached');

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/introspect'));
});

/*
| Degraded mode (hybrid only).
*/

function hybridWithTestKey(bool $degraded): TokenFactory
{
    $tokens = new TokenFactory;
    config([
        'ubo-auth.strategy' => 'hybrid',
        'ubo-auth.public_key' => $tokens->publicKey(),
        'ubo-auth.degraded_mode' => $degraded,
    ]);

    return $tokens;
}

test('in degraded mode a locally valid token is accepted while introspection is down', function () {
    Log::spy();
    $tokens = hybridWithTestKey(degraded: true);
    Http::fake([
        ...fakeTokenEndpoint(),
        'https://api-auth.test/api/auth/v1/introspect' => readTimeout(),
    ]);

    expect(app(TokenValidator::class)->validate($tokens->forClient('SIS', ['geografia:leer']))->clientId)->toBe('SIS')
        ->and(app(TokenValidator::class)->validate($tokens->forClient('SIS', ['geografia:leer']))->clientId)->toBe('SIS');

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'degraded mode'))->once();
});

test('in degraded mode a token API-Auth reports revoked is still rejected', function () {
    $tokens = hybridWithTestKey(degraded: true);
    Http::fake([
        ...fakeTokenEndpoint(),
        'https://api-auth.test/api/auth/v1/introspect' => Http::response(['active' => false]),
    ]);

    app(TokenValidator::class)->validate($tokens->forClient('SIS'));
})->throws(InvalidToken::class);

test('in degraded mode a forged token is rejected without asking API-Auth', function () {
    $tokens = hybridWithTestKey(degraded: true);
    Http::fake([
        ...fakeTokenEndpoint(),
        'https://api-auth.test/api/auth/v1/introspect' => readTimeout(),
    ]);

    try {
        app(TokenValidator::class)->validate($tokens->forged('SIS'));
        $this->fail('InvalidToken was not thrown');
    } catch (InvalidToken $e) {
        expect($e->reason)->toBe('invalid_signature');
    }

    Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/introspect'));
});

test('the degraded mode does not apply to the introspection strategy', function () {
    config(['ubo-auth.degraded_mode' => true]);
    Http::fake([
        ...fakeTokenEndpoint(),
        'https://api-auth.test/api/auth/v1/introspect' => readTimeout(),
    ]);

    app(TokenValidator::class)->validate('opaque-token');
})->throws(AuthServerUnavailable::class);

test('without degraded mode hybrid fails closed while introspection is down', function () {
    $tokens = hybridWithTestKey(degraded: false);
    Http::fake([
        ...fakeTokenEndpoint(),
        'https://api-auth.test/api/auth/v1/introspect' => readTimeout(),
    ]);

    app(TokenValidator::class)->validate($tokens->forClient('SIS'));
})->throws(AuthServerUnavailable::class);
