<?php

use Illuminate\Support\Facades\Auth;
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

    expect(vaultEntry()['audiences'])->toBe(['portal-web', 'api-maestros'])
        ->and(vaultEntry()['client_id'])->toBe('portal-web');
});

test('the login of a test lives in the vault and is never renewed or checked with IAM', function () {
    Http::fake();
    UboLogin::actingAs(id: '42', ttl: 3600);

    $this->travel(59)->minutes();

    expect(Auth::guard('ubo')->check())->toBeTrue()
        ->and(session('ubo_login'))->toBeString()
        ->and(vaultEntry()['id'])->toBe('42')
        ->and(vaultEntry()['access_token'])->toBe('test-access-token');
    Http::assertNothingSent();
});

test('the id of a simulated person is their uuid, and the internal id can be given apart', function () {
    UboLogin::actingAs(id: '9f1c2b3a-0000-4000-8000-00000000abcd', sub: '42');

    expect(Auth::guard('ubo')->id())->toBe('9f1c2b3a-0000-4000-8000-00000000abcd')
        ->and(Auth::guard('ubo')->user()->sub)->toBe('42');

    UboLogin::actingAs(id: '7');

    expect(Auth::guard('ubo')->id())->toBe('7')
        ->and(Auth::guard('ubo')->user()->sub)->toBe('7');
});

test('it cannot be used in production', function () {
    app()->detectEnvironment(fn () => 'production');

    expect(fn () => UboLogin::actingAs())->toThrow(LogicException::class, 'production');
    expect(session('ubo_login'))->toBeNull();
});
