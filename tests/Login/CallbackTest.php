<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use UBO\Common\Login\Exceptions\LoginFailed;
use UBO\Common\Login\Exceptions\LoginNotConfigured;

test('a login that comes back with its state and a code starts the session', function () {
    [$state, $verifier] = startedLogin();
    iamIssues(personToken());

    $this->withBrowserSession()->get('/iam/callback?code=the-code&state='.$state)->assertRedirect('/');

    expect(session('ubo_login'))->toMatchArray([
        'id' => '42',
        'client_id' => 'login-client',
        'roles' => ['coordinador'],
        'scopes' => ['geografia:leer'],
        'audiences' => ['login-client', 'api-maestros'],
    ])->and(session('ubo_login_pending'))->toBeNull();
});

test('the code is exchanged with the verifier of the session and the login client', function () {
    [$state, $verifier] = startedLogin();
    iamIssues(personToken());

    $this->withBrowserSession()->get('/iam/callback?code=the-code&state='.$state);

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request->url() === IAM_TOKEN_URL
        && $request->data() === [
            'grant_type' => 'authorization_code',
            'client_id' => 'login-client',
            'client_secret' => 'login-secret',
            'code' => 'the-code',
            'redirect_uri' => 'https://portal.test/iam/callback',
            'code_verifier' => $verifier,
        ]);
});

test('a wrong state is refused without calling IAM', function () {
    startedLogin();
    Http::fake();

    $this->withBrowserSession()->get('/iam/callback?code=the-code&state=another-state')->assertStatus(400);

    Http::assertNothingSent();
    expect(session('ubo_login'))->toBeNull();
});

test('a callback in a session that never started a login is refused', function () {
    Http::fake();

    $this->get('/iam/callback?code=the-code&state=anything')->assertStatus(400);

    Http::assertNothingSent();
});

test('a state that took too long is refused', function () {
    [$state] = startedLogin();
    Http::fake();
    $this->travel(11)->minutes();

    $this->withBrowserSession()->get('/iam/callback?code=the-code&state='.$state)->assertStatus(400);

    Http::assertNothingSent();
});

test('the same callback cannot be used twice', function () {
    [$state] = startedLogin();
    iamIssues(personToken());

    $this->withBrowserSession()->get('/iam/callback?code=the-code&state='.$state)->assertRedirect('/');
    $this->withBrowserSession()->get('/iam/callback?code=the-code&state='.$state)->assertStatus(400);

    Http::assertSentCount(1);
});

test('a callback without a code is refused', function () {
    [$state] = startedLogin();
    Http::fake();

    $this->withBrowserSession()->get('/iam/callback?state='.$state)->assertStatus(400);

    Http::assertNothingSent();
});

test('an error from IAM ends the login without asking for tokens', function () {
    [$state] = startedLogin();
    Http::fake();

    $this->withBrowserSession()->get('/iam/callback?error=access_denied&state='.$state)->assertStatus(403);

    Http::assertNothingSent();
    expect(session('ubo_login_pending'))->toBeNull()
        ->and(session('ubo_login'))->toBeNull();
});

test('the error of IAM is kept for the logs', function () {
    [$state] = startedLogin();
    $this->withoutExceptionHandling();

    try {
        $this->withBrowserSession()->get('/iam/callback?error=access_denied&state='.$state);
        $this->fail('LoginFailed was not thrown');
    } catch (LoginFailed $e) {
        expect($e->getStatusCode())->toBe(403)
            ->and($e->error)->toBe('access_denied');
    }
});

test('a code IAM rejects ends the login', function () {
    [$state] = startedLogin();
    iamIssues('', 400);

    $this->withBrowserSession()->get('/iam/callback?code=used-code&state='.$state)->assertStatus(400);

    expect(session('ubo_login'))->toBeNull();
});

test('a rejection carries the code of IAM', function () {
    [$state] = startedLogin();
    iamIssues('', 400);
    $this->withoutExceptionHandling();

    try {
        $this->withBrowserSession()->get('/iam/callback?code=used-code&state='.$state);
        $this->fail('LoginFailed was not thrown');
    } catch (LoginFailed $e) {
        expect($e->getStatusCode())->toBe(400)
            ->and($e->error)->toBe('invalid_grant');
    }
});

test('IAM being down is a 503 and no session', function () {
    [$state] = startedLogin();
    Http::fake([IAM_TOKEN_URL => connectionRefused()]);

    $this->withBrowserSession()->get('/iam/callback?code=the-code&state='.$state)->assertStatus(503);

    expect(session('ubo_login'))->toBeNull();
});

test('a token signed with another key is refused', function () {
    [$state] = startedLogin();
    iamIssues($this->tokens->forged('login-client', []));

    $this->withBrowserSession()->get('/iam/callback?code=the-code&state='.$state)->assertStatus(400);

    expect(session('ubo_login'))->toBeNull();
});

test('a token issued to another client is refused', function () {
    [$state] = startedLogin();
    iamIssues(personToken(['client' => 'another-client']));

    $this->withBrowserSession()->get('/iam/callback?code=the-code&state='.$state)->assertStatus(400);

    expect(session('ubo_login'))->toBeNull();
});

test('a token that is not a person is refused', function () {
    [$state] = startedLogin();
    iamIssues($this->tokens->forClient('login-client', ['geografia:leer'], roles: ['lector']));

    $this->withBrowserSession()->get('/iam/callback?code=the-code&state='.$state)->assertStatus(400);

    expect(session('ubo_login'))->toBeNull();
});

test('a person without roles in the application does not get in', function () {
    [$state] = startedLogin();
    iamIssues(personToken(['roles' => [], 'scopes' => []]));

    $this->withBrowserSession()->get('/iam/callback?code=the-code&state='.$state)->assertStatus(403);

    expect(session('ubo_login'))->toBeNull();
});

test('the login works without machine credentials and never introspects', function () {
    config(['ubo-auth.client_id' => null, 'ubo-auth.client_secret' => null, 'ubo-auth.strategy' => 'hybrid']);
    [$state] = startedLogin();
    iamIssues(personToken());

    $this->withBrowserSession()->get('/iam/callback?code=the-code&state='.$state)->assertRedirect('/');

    Http::assertSentCount(1);
    expect(session('ubo_login.id'))->toBe('42');
});

test('the session id of before the login stops carrying anything', function () {
    [$state] = startedLogin();
    iamIssues(personToken());
    $before = app('session.store')->getId();

    $this->withBrowserSession()->get('/iam/callback?code=the-code&state='.$state)->assertRedirect('/');

    expect(app('session.store')->getId())->not->toBe($before)
        ->and(app('session.store')->getHandler()->read($before))->toBe('');
});

test('the person lands on the page they asked for', function () {
    [$state] = startedLogin('?redirect=/informes/3');
    iamIssues(personToken());

    $this->withBrowserSession()->get('/iam/callback?code=the-code&state='.$state)->assertRedirect('/informes/3');
});

test('the page remembered for a guest is used once', function () {
    $this->withSession(['url.intended' => 'https://portal.test/informes/3']);
    [$state] = startedLogin();
    iamIssues(personToken());

    $this->withBrowserSession()->get('/iam/callback?code=the-code&state='.$state)->assertRedirect('https://portal.test/informes/3');

    expect(session('url.intended'))->toBeNull();
});

test('without a page to go back to the person lands on the configured home', function () {
    config(['ubo-login.home' => '/inicio']);
    [$state] = startedLogin();
    iamIssues(personToken());

    $this->withBrowserSession()->get('/iam/callback?code=the-code&state='.$state)->assertRedirect('/inicio');
});

test('the access token never reaches the browser', function () {
    [$state] = startedLogin();
    $access = personToken();
    iamIssues($access);

    $response = $this->withBrowserSession()->get('/iam/callback?code=the-code&state='.$state);

    expect(json_encode($response->headers->all()))->not->toContain($access)
        ->and($response->getContent())->not->toContain($access)
        ->and(json_encode(array_map(fn ($cookie) => $cookie->getValue(), $response->headers->getCookies())))->not->toContain($access);
});

test('a session that lives in the browser is refused before the code is exchanged', function () {
    [$state] = startedLogin();
    config(['session.driver' => 'cookie']);
    Http::fake();
    $this->withoutExceptionHandling();

    expect(fn () => $this->withBrowserSession()->get('/iam/callback?code=the-code&state='.$state))
        ->toThrow(LogicException::class, 'SESSION_DRIVER=cookie');
    Http::assertNothingSent();
});

test('without the secret the callback names what is missing', function () {
    config(['ubo-login.client_secret' => null]);
    $this->withoutExceptionHandling();

    expect(fn () => $this->get('/iam/callback?code=the-code&state=x'))->toThrow(LoginNotConfigured::class, 'UBO_LOGIN_CLIENT_SECRET');
});
