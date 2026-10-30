<?php

use Illuminate\Support\Facades\Http;
use UBO\Common\Login\Exceptions\LoginNotConfigured;

test('the browser is sent to the authorization endpoint of IAM with PKCE', function () {
    $response = $this->get('/iam/login')->assertRedirectContains('https://iam.test/api/auth/oauth/authorize?');
    $params = authorizeParams($response);

    expect($params)->toHaveKeys(['response_type', 'client_id', 'redirect_uri', 'state', 'code_challenge', 'code_challenge_method'])
        ->and($params['response_type'])->toBe('code')
        ->and($params['client_id'])->toBe('login-client')
        ->and($params['redirect_uri'])->toBe('https://portal.test/iam/callback')
        ->and($params['code_challenge_method'])->toBe('S256')
        ->and($params)->not->toHaveKey('scope')
        ->and($params)->not->toHaveKey('prompt');
    Http::assertNothingSent();
});

test('the challenge is the SHA-256 of the verifier kept in the session', function () {
    $params = authorizeParams($this->get('/iam/login'));
    $pending = session('ubo_login_pending');

    expect($pending['state'])->toBe($params['state'])
        ->and($pending['verifier'])->toMatch('/^[A-Za-z0-9_-]{43}$/')
        ->and($params['code_challenge'])->toBe(rtrim(strtr(base64_encode(hash('sha256', $pending['verifier'], true)), '+/', '-_'), '='))
        ->and($pending['expires_at'])->toBe(now()->getTimestamp() + 600);
});

test('each start is different and only the last one stays in the session', function () {
    $first = authorizeParams($this->get('/iam/login'));
    $firstVerifier = session('ubo_login_pending.verifier');
    $second = authorizeParams($this->withBrowserSession()->get('/iam/login'));

    expect($second['state'])->not->toBe($first['state'])
        ->and($second['code_challenge'])->not->toBe($first['code_challenge'])
        ->and(session('ubo_login_pending.state'))->toBe($second['state'])
        ->and(session('ubo_login_pending.verifier'))->not->toBe($firstVerifier);
});

test('the browser goes to the public address and the server keeps using the internal one', function () {
    config(['ubo-login.public_url' => 'https://iam.ubo.cl', 'ubo-auth.url' => 'http://api-auth']);

    $this->get('/iam/login')->assertRedirectContains('https://iam.ubo.cl/api/auth/oauth/authorize?');
});

test('without a public address the internal one is used for the browser too', function () {
    config(['ubo-login.public_url' => null]);

    $this->get('/iam/login')->assertRedirectContains('https://api-auth.test/api/auth/oauth/authorize?');
});

test('a forged Host header does not change the redirect uri', function () {
    $response = $this->get('http://evil.test/iam/login', ['Host' => 'evil.test']);

    expect(authorizeParams($response)['redirect_uri'])->toBe('https://portal.test/iam/callback');
});

test('a configured redirect uri is sent as it is', function () {
    config(['ubo-login.redirect_uri' => 'https://portal.test/registered/callback']);

    expect(authorizeParams($this->get('/iam/login'))['redirect_uri'])->toBe('https://portal.test/registered/callback');
});

test('the person comes back to the page they asked for', function () {
    $this->get('/iam/login?redirect=/informes/3');

    expect(session('ubo_login_pending.destination'))->toBe('/informes/3');
});

test('the page remembered by Laravel for a guest is the destination', function () {
    $this->withSession(['url.intended' => 'https://portal.test/informes/3'])->get('/iam/login');

    expect(session('ubo_login_pending.destination'))->toBe('https://portal.test/informes/3');
});

test('a destination of another site is not kept', function (string $destination) {
    $this->get('/iam/login?redirect='.urlencode($destination));

    expect(session('ubo_login_pending.destination'))->toBeNull();
})->with(['https://evil.test/phish', '//evil.test', 'javascript:alert(1)']);

test('prompt=login is sent on every start when it is configured', function () {
    config(['ubo-login.prompt' => 'login']);

    expect(authorizeParams($this->get('/iam/login'))['prompt'])->toBe('login');
});

test('reauth=1 asks for the password on that start only', function () {
    expect(authorizeParams($this->get('/iam/login?reauth=1'))['prompt'])->toBe('login')
        ->and(authorizeParams($this->withBrowserSession()->get('/iam/login')))->not->toHaveKey('prompt');
});

test('without the login client the start names what is missing', function (string $key, string $variable) {
    config([$key => null]);
    $this->withoutExceptionHandling();

    $this->get('/iam/login');
})->with([
    ['ubo-login.client_id', 'UBO_LOGIN_CLIENT_ID'],
    ['ubo-login.client_secret', 'UBO_LOGIN_CLIENT_SECRET'],
])->throws(LoginNotConfigured::class);

test('the secret is reported by name', function () {
    config(['ubo-login.client_secret' => null]);
    $this->withoutExceptionHandling();

    expect(fn () => $this->get('/iam/login'))->toThrow(LoginNotConfigured::class, 'UBO_LOGIN_CLIENT_SECRET');
});

test('a session that lives in the browser is refused before leaving for IAM', function () {
    config(['session.driver' => 'cookie']);
    $this->withoutExceptionHandling();

    $this->get('/iam/login');
})->throws(LogicException::class, 'SESSION_DRIVER=cookie');
