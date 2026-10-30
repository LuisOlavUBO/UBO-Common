<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use UBO\Common\Login\Domain\UboUser;
use UBO\Common\Login\Infrastructure\UboGuard;
use UBO\Common\Login\Testing\UboLogin;

beforeEach(function () {
    Route::middleware('web')->get('/whoami', fn () => [
        'check' => Auth::guard('ubo')->check(),
        'id' => Auth::guard('ubo')->id(),
        'roles' => Auth::guard('ubo')->user()?->roles,
    ]);
});

test('an application can declare its own guard with the ubo driver', function () {
    config(['auth.guards.web' => ['driver' => 'ubo']]);

    expect(Auth::guard('web'))->toBeInstanceOf(UboGuard::class);
});

test('the user is the person of the token', function () {
    $tokens = $this->tokens;
    [$state] = startedLogin();
    iamIssues($tokens->forUser('42', 'login-client', ['geografia:leer', 'geografia:escribir'], roles: ['coordinador'], audiences: ['api-maestros', 'portal']));
    $this->withBrowserSession()->get('/iam/callback?code=the-code&state='.$state)->assertRedirect('/');

    $this->withBrowserSession()->get('/whoami')->assertExactJson(['check' => true, 'id' => '42', 'roles' => ['coordinador']]);

    $user = Auth::guard('ubo')->user();

    expect($user)->toBeInstanceOf(UboUser::class)
        ->and($user->getAuthIdentifier())->toBe('42')
        ->and($user->clientId)->toBe('login-client')
        ->and($user->roles)->toBe(['coordinador'])
        ->and($user->scopes)->toBe(['geografia:leer', 'geografia:escribir'])
        ->and($user->audiences)->toBe(['login-client', 'api-maestros', 'portal'])
        ->and($user->hasRole('coordinador'))->toBeTrue()
        ->and($user->hasScope('geografia:leer'))->toBeTrue();
});

test('roles are matched as written', function () {
    UboLogin::actingAs(roles: ['Coordinador'], scopes: ['geografia:leer']);
    $user = Auth::guard('ubo')->user();

    expect($user->hasRole('Coordinador'))->toBeTrue()
        ->and($user->hasRole('coordinador'))->toBeFalse()
        ->and($user->hasScope('geografia:leer'))->toBeTrue()
        ->and($user->hasScope('geografia:escribir'))->toBeFalse()
        ->and($user->hasScope('*'))->toBeFalse();
});

test('without a login there is no user', function () {
    $this->get('/whoami')->assertExactJson(['check' => false, 'id' => null, 'roles' => null]);

    expect(Auth::guard('ubo')->guest())->toBeTrue()
        ->and(Auth::guard('ubo')->user())->toBeNull();
});

test('the user does not hold the token', function () {
    UboLogin::actingAs(id: '42');

    expect(json_encode(Auth::guard('ubo')->user()))->not->toContain('test-access-token')
        ->and(serialize(Auth::guard('ubo')->user()))->not->toContain('test-access-token');
});

test('the login ends when the access token expires', function () {
    UboLogin::actingAs(id: '42', ttl: 3600);

    $this->get('/whoami')->assertJson(['check' => true]);

    $this->travel(61)->minutes();

    $this->get('/whoami')->assertExactJson(['check' => false, 'id' => null, 'roles' => null]);
    expect(session('ubo_login'))->toBeNull();
});

test('the leeway of the auth module counts before the token expires', function () {
    config(['ubo-auth.leeway' => 30]);
    UboLogin::actingAs(id: '42', ttl: 3600);

    $this->travel(3600 - 31)->seconds();
    expect(Auth::guard('ubo')->check())->toBeTrue();

    $this->travel(2)->seconds();
    expect(Auth::guard('ubo')->check())->toBeFalse();
});

test('a token that is still valid keeps the person logged in', function () {
    UboLogin::actingAs(id: '42', ttl: 3600);

    $this->travel(59)->minutes();

    $this->get('/whoami')->assertJson(['check' => true, 'id' => '42']);
});

test('one request never sees the user of another', function () {
    UboLogin::actingAs(id: '42');
    $this->get('/whoami')->assertJson(['id' => '42']);

    app('session.store')->flush();

    $this->get('/whoami')->assertJson(['id' => null]);
});

test('a callback that ends in a login does not call IAM again afterwards', function () {
    UboLogin::actingAs(id: '42');
    Http::fake();

    $this->get('/whoami');

    Http::assertNothingSent();
});
