<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use UBO\Common\Login\Testing\UboLogin;

const IAM_LOGOUT_URL = 'https://api-auth.test/api/auth/v1/logout';

test('the logout revokes the token, ends the session and sends the person to IAM', function () {
    Http::fake([IAM_LOGOUT_URL => Http::response(['message' => 'Session closed.'])]);
    UboLogin::actingAs(id: '42', roles: ['coordinador']);
    $csrf = session()->token();

    $response = $this->post('/iam/logout');

    $response->assertRedirect('https://iam.test/api/auth/logout?client_id=login-client&return=https%3A%2F%2Fportal.test%2F');
    Http::assertSent(fn (Request $request) => $request->url() === IAM_LOGOUT_URL
        && $request->header('Authorization') === ['Bearer test-access-token']);
    expect(session('ubo_login'))->toBeNull()
        ->and(session()->token())->not->toBe($csrf);
});

test('the return address comes from the configuration, never from the request', function () {
    Http::fake([IAM_LOGOUT_URL => Http::response([])]);
    config(['ubo-login.logout_return' => 'https://portal.test/adios']);
    UboLogin::actingAs();

    $this->post('/iam/logout?return=https://evil.test/phish', ['return' => 'https://evil.test/phish'])
        ->assertRedirect('https://iam.test/api/auth/logout?client_id=login-client&return=https%3A%2F%2Fportal.test%2Fadios');
});

test('the return address defaults to the home of the application', function () {
    Http::fake([IAM_LOGOUT_URL => Http::response([])]);
    config(['ubo-login.home' => '/inicio']);
    UboLogin::actingAs();

    $this->post('/iam/logout')
        ->assertRedirect('https://iam.test/api/auth/logout?client_id=login-client&return=https%3A%2F%2Fportal.test%2Finicio');
});

test('IAM not answering the revocation does not stop the logout', function () {
    Http::fake([IAM_LOGOUT_URL => connectionRefused()]);
    Log::spy();
    UboLogin::actingAs();

    $this->post('/iam/logout')->assertRedirectContains('https://iam.test/api/auth/logout?');

    expect(session('ubo_login'))->toBeNull();
    Log::shouldHaveReceived('warning')->once();
});

test('a person without a session goes to IAM without revoking anything', function () {
    Http::fake();

    $this->post('/iam/logout')->assertRedirectContains('https://iam.test/api/auth/logout?');

    Http::assertNothingSent();
});

test('a local logout revokes this token and stays in the application', function () {
    Http::fake([IAM_LOGOUT_URL => Http::response([])]);
    UboLogin::actingAs();

    $this->post('/iam/logout', ['scope' => 'local'])->assertRedirect('/');

    Http::assertSentCount(1);
    expect(session('ubo_login'))->toBeNull();
});

test('a local logout goes to the configured home', function () {
    Http::fake([IAM_LOGOUT_URL => Http::response([])]);
    config(['ubo-login.home' => '/inicio']);
    UboLogin::actingAs();

    $this->post('/iam/logout?scope=local')->assertRedirect('/inicio');
});

test('the logout is a POST protected against CSRF', function () {
    $route = app('router')->getRoutes()->getByName('ubo.logout');
    $middleware = app('router')->gatherRouteMiddleware($route);

    expect($route->methods())->toBe(['POST'])
        ->and(collect($middleware)->filter(fn ($m) => is_string($m) && preg_match('/VerifyCsrfToken|PreventRequestForgery/', $m)))->not->toBeEmpty();
    $this->get('/iam/logout')->assertStatus(405);
});
