<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Builder;
use Lcobucci\JWT\Token\Parser;
use UBO\Common\Auth\Domain\VerifiedToken;
use UBO\Common\Auth\Exceptions\InsufficientScope;
use UBO\Common\Auth\Testing\TestKey;
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

/*
| Roles of the token.
*/

/** A machine token signed with the test key, with exactly the claims given (TokenFactory always writes "roles"). */
function tokenWithClaims(array $claims): string
{
    $builder = (new Builder(new JoseEncoder, ChainedFormatter::default()))
        ->permittedFor('SIS_SQLSRV')
        ->identifiedBy('jti-1')
        ->issuedAt(now()->toDateTimeImmutable())
        ->canOnlyBeUsedAfter(now()->toDateTimeImmutable())
        ->expiresAt(now()->addHour()->toDateTimeImmutable())
        ->relatedTo('SIS_SQLSRV')
        ->withClaim('scopes', ['geografia:leer']);

    foreach ($claims as $name => $value) {
        $builder = $builder->withClaim($name, $value);
    }

    return $builder
        ->getToken(new Sha256, InMemory::file(dirname(TestKey::PUBLIC_KEY_PATH).'/test-private.key'))
        ->toString();
}

test('the roles of the token are available in the request', function () {
    config(['ubo-auth.allow_user_tokens' => true]);
    Route::middleware('ubo.auth')->get('/protected/roles', fn (Request $request) => [
        'roles' => VerifiedToken::from($request)->roles,
        'lector' => VerifiedToken::from($request)->hasRole('lector'),
    ]);

    $token = $this->tokens->forUser('42', 'SIS_SQLSRV', ['geografia:leer'], roles: ['coordinador', 'lector']);

    $this->withToken($token)->getJson('/protected/roles')
        ->assertOk()
        ->assertExactJson(['roles' => ['coordinador', 'lector'], 'lector' => true]);
});

test('a machine token reports its roles too', function () {
    Route::middleware('ubo.auth')->get('/protected/roles', fn (Request $request) => VerifiedToken::from($request)->roles);

    $this->withToken($this->tokens->forClient('SIS_SQLSRV', ['geografia:leer'], roles: ['lector']))
        ->getJson('/protected/roles')
        ->assertOk()
        ->assertExactJson(['lector']);
});

test('a token without the roles claim is accepted and has none', function () {
    Route::middleware('ubo.auth')->get('/protected/roles', fn (Request $request) => VerifiedToken::from($request)->roles);

    $this->withToken(tokenWithClaims([]))->getJson('/protected/roles')->assertOk()->assertExactJson([]);
});

test('a roles claim that is not a list is read as no roles', function () {
    Route::middleware('ubo.auth')->get('/protected/roles', fn (Request $request) => VerifiedToken::from($request)->roles);

    $this->withToken(tokenWithClaims(['roles' => 'lector']))->getJson('/protected/roles')->assertOk()->assertExactJson([]);
});

test('role names that are not text are dropped', function () {
    Route::middleware('ubo.auth')->get('/protected/roles', fn (Request $request) => VerifiedToken::from($request)->roles);

    $this->withToken(tokenWithClaims(['roles' => ['lector', 7, null, 'admin']]))
        ->getJson('/protected/roles')->assertOk()->assertExactJson(['lector', 'admin']);
});

test('TokenFactory issues user tokens with the audiences API-Auth gives them', function () {
    $token = $this->tokens->forUser('42', 'SIS_SQLSRV', [], audiences: ['api-maestros', 'portal']);

    $claims = (new Parser(new JoseEncoder))->parse($token)->claims();

    expect($claims->get('aud'))->toBe(['SIS_SQLSRV', 'api-maestros', 'portal']);
});

test('TokenFactory still issues user tokens for the API under test without audiences', function () {
    config(['ubo-auth.api' => 'api-maestros']);

    $token = $this->tokens->forUser('42', 'SIS_SQLSRV');

    expect((new Parser(new JoseEncoder))->parse($token)->claims()->get('aud'))
        ->toBe(['SIS_SQLSRV', 'api-maestros']);
});
