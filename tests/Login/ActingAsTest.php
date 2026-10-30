<?php

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use UBO\Common\Login\Testing\UboLogin;

test('a test can act as a person without IAM', function () {
    Http::fake();
    Route::middleware(['web', 'ubo.login', 'ubo.role:coordinador'])->get('/panel', fn () => trim(Blade::render('@can("geografia:leer") leer @endcan @cannot("geografia:escribir") sin-escribir @endcannot').' '.auth()->id()));

    UboLogin::actingAs(id: '42', roles: ['coordinador'], scopes: ['geografia:leer']);

    $this->get('/panel')->assertOk()->assertSeeInOrder(['leer', 'sin-escribir', '42']);
    Http::assertNothingSent();
});

test('the person is not logged in until the test says so', function () {
    Route::middleware(['web', 'ubo.login'])->get('/panel', fn () => 'panel');

    $this->getJson('/panel')->assertUnauthorized();
});

test('the audiences include the client id and the ones given', function () {
    UboLogin::actingAs(audiences: ['api-maestros'], clientId: 'portal-web');

    expect(session('ubo_login.audiences'))->toBe(['portal-web', 'api-maestros'])
        ->and(session('ubo_login.client_id'))->toBe('portal-web');
});

test('it cannot be used in production', function () {
    app()->detectEnvironment(fn () => 'production');

    expect(fn () => UboLogin::actingAs())->toThrow(LogicException::class, 'production');
    expect(session('ubo_login'))->toBeNull();
});
