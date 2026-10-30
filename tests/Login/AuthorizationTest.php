<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use UBO\Common\Login\Domain\UboUser;
use UBO\Common\Login\Testing\UboLogin;

beforeEach(function () {
    Route::middleware(['web', 'ubo.login'])->get('/informes', fn () => 'informes');
    Route::middleware(['web', 'ubo.login'])->get('/quien', fn () => [
        'default' => Auth::user() instanceof UboUser,
        'id' => Auth::id(),
    ]);
    Route::middleware(['web', 'ubo.login'])->get('/can', fn () => [
        'leer' => Gate::allows('geografia:leer'),
        'escribir' => Gate::allows('geografia:escribir'),
        'exportar' => Gate::allows('exportar'),
        'blade' => trim(Blade::render('@can("geografia:leer")si @else no @endcan')),
    ]);
    Route::middleware(['web', 'ubo.login', 'can:geografia:leer'])->get('/leer', fn () => 'leer');
    Route::middleware(['web', 'ubo.login', 'can:geografia:escribir'])->get('/escribir', fn () => 'escribir');
    Route::middleware(['web', 'ubo.role:coordinador,admin'])->get('/admin', fn () => 'admin');
    Route::middleware(['web', 'ubo.role:coordinador'])->get('/coordinacion', fn () => 'coordinacion');
});

test('a scope of the token is an ability, in Gate, @can and can:', function () {
    UboLogin::actingAs(scopes: ['geografia:leer']);

    $this->getJson('/can')->assertExactJson(['leer' => true, 'escribir' => false, 'exportar' => false, 'blade' => 'si']);
    $this->get('/leer')->assertOk();
});

test('a scope the token lacks is not an ability', function () {
    UboLogin::actingAs(scopes: ['geografia:leer']);

    $this->get('/escribir')->assertForbidden();
});

test('an ability that is not a scope is left to the policies of the application', function () {
    Gate::define('exportar', fn ($user) => $user instanceof UboUser && $user->hasRole('coordinador'));
    UboLogin::actingAs(roles: ['coordinador'], scopes: []);

    $this->getJson('/can')->assertJson(['exportar' => true, 'leer' => false]);
});

test('a policy can still deny what a scope does not grant', function () {
    Gate::define('exportar', fn () => false);
    UboLogin::actingAs(roles: ['coordinador'], scopes: ['geografia:leer']);

    $this->getJson('/can')->assertJson(['exportar' => false, 'leer' => true]);
});

test('a visitor gets no ability from scopes', function () {
    expect(Gate::allows('geografia:leer'))->toBeFalse()
        ->and(Gate::forUser(null)->allows('geografia:leer'))->toBeFalse();
});

test('the ubo guard is the default one inside a route behind ubo.login', function () {
    UboLogin::actingAs(id: '42', roles: ['lector']);

    $this->getJson('/quien')->assertExactJson(['default' => true, 'id' => '42']);
});

test('a person without a login is sent to the login and remembered', function () {
    $this->get('https://portal.test/informes')->assertRedirect('https://portal.test/iam/login');

    expect(session('url.intended'))->toBe('https://portal.test/informes');
});

test('after the login the person lands on the page they asked for', function () {
    $this->get('https://portal.test/informes')->assertRedirect('https://portal.test/iam/login');
    $this->withBrowserSession()->get('https://portal.test/iam/login');

    expect(session('ubo_login_pending.destination'))->toBe('https://portal.test/informes');

    [$state] = [session('ubo_login_pending.state')];
    iamIssues(personToken());

    $this->withBrowserSession()->get('https://portal.test/iam/callback?code=the-code&state='.$state)->assertRedirect('https://portal.test/informes');
});

test('a JSON request without a login answers 401', function () {
    $this->getJson('/informes')->assertUnauthorized();
});

test('a person with a login gets through', function () {
    UboLogin::actingAs(id: '42');

    $this->get('/informes')->assertOk()->assertSee('informes');
});

test('a role in the list lets the person through', function () {
    UboLogin::actingAs(roles: ['admin']);

    $this->get('/admin')->assertOk();
});

test('a role that is not in the list answers 403', function () {
    UboLogin::actingAs(roles: ['lector']);

    $this->get('/admin')->assertForbidden();
});

test('role names are compared as written', function () {
    UboLogin::actingAs(roles: ['Coordinador']);

    $this->get('/coordinacion')->assertForbidden();
});

test('ubo.role without a login behaves like ubo.login', function () {
    $this->get('https://portal.test/admin')->assertRedirect('https://portal.test/iam/login');
    $this->getJson('/admin')->assertUnauthorized();
});
