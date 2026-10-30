<?php

use Illuminate\Routing\Router;
use UBO\Common\Auth\AuthSettings;
use UBO\Common\Login\Exceptions\LoginNotConfigured;
use UBO\Common\Login\Infrastructure\UboGuard;
use UBO\Common\Login\LoginSettings;
use UBO\Common\Tests\TestCase;

function loginSettings(array $login = [], ?string $appUrl = 'https://portal.test', array $auth = ['url' => 'https://api-auth.test']): LoginSettings
{
    return LoginSettings::fromConfig($login, AuthSettings::fromConfig($auth), $appUrl);
}

test('turning the module on registers its routes, guard and middleware', function () {
    $router = app(Router::class);

    expect($router->has('ubo.login'))->toBeTrue()
        ->and($router->has('ubo.login.callback'))->toBeTrue()
        ->and($router->has('ubo.logout'))->toBeTrue()
        ->and($router->getMiddleware())->toHaveKeys(['ubo.login', 'ubo.role'])
        ->and(app('auth')->guard('ubo'))->toBeInstanceOf(UboGuard::class)
        ->and(config('auth.defaults.guard'))->not->toBe('ubo');
});

test('the routes live under the configured prefix', function () {
    TestCase::$bootConfig = ['ubo-login.route_prefix' => 'sso'];
    $this->refreshApplication();

    expect(route('ubo.login', absolute: false))->toBe('/sso/login')
        ->and(route('ubo.login.callback', absolute: false))->toBe('/sso/callback')
        ->and(route('ubo.logout', absolute: false))->toBe('/sso/logout');
});

test('empty values use the defaults', function () {
    $settings = loginSettings(['route_prefix' => '', 'home' => '', 'state_ttl' => '', 'prompt' => 'other', 'client_id' => '', 'public_url' => '']);

    expect($settings->routePrefix)->toBe('iam')
        ->and($settings->home)->toBe('/')
        ->and($settings->stateTtl)->toBe(600)
        ->and($settings->prompt)->toBeNull()
        ->and($settings->clientId)->toBeNull()
        ->and($settings->publicUrl)->toBe('https://api-auth.test');
});

test('a zero or non numeric state ttl uses the default', function (mixed $ttl) {
    expect(loginSettings(['state_ttl' => $ttl])->stateTtl)->toBe(600);
})->with([0, -5, 'soon']);

test('the public url is kept apart from the internal one', function () {
    $settings = loginSettings(['public_url' => 'https://iam.ubo.cl/'], auth: ['url' => 'http://api-auth']);

    expect($settings->authorizeUrl())->toBe('https://iam.ubo.cl/api/auth/oauth/authorize')
        ->and($settings->logoutUrl())->toBe('https://iam.ubo.cl/api/auth/logout');
});

test('the redirect uri is the callback under APP_URL unless one is configured', function () {
    expect(loginSettings(appUrl: 'https://portal.ubo.cl/')->redirectUri())->toBe('https://portal.ubo.cl/iam/callback')
        ->and(loginSettings(['route_prefix' => 'sso'])->redirectUri())->toBe('https://portal.test/sso/callback')
        ->and(loginSettings(['redirect_uri' => 'https://portal.test/registered'])->redirectUri())->toBe('https://portal.test/registered');
});

test('a missing value is reported by name when it is used, not when the module registers', function () {
    config(['ubo-login.client_id' => null, 'ubo-login.client_secret' => null]);

    $settings = app(LoginSettings::class);

    expect(fn () => $settings->clientId())->toThrow(LoginNotConfigured::class, 'UBO_LOGIN_CLIENT_ID')
        ->and(fn () => $settings->clientSecret())->toThrow(LoginNotConfigured::class, 'UBO_LOGIN_CLIENT_SECRET');
});

test('the package boots with the module on and nothing configured, as in a build without .env', function () {
    config([
        'ubo-login.public_url' => null,
        'ubo-login.client_id' => null,
        'ubo-login.client_secret' => null,
        'ubo-auth.url' => null,
        'app.url' => null,
    ]);

    expect(app(Router::class)->has('ubo.login'))->toBeTrue()
        ->and(fn () => app(LoginSettings::class)->authorizeUrl())->toThrow(LoginNotConfigured::class, 'UBO_AUTH_URL');
});

test('a destination is local when it is a path or on the host of APP_URL', function (string $destination, bool $local) {
    expect(loginSettings()->isLocal($destination))->toBe($local);
})->with([
    'path' => ['/informes/3', true],
    'path with query' => ['/informes?x=1', true],
    'same host' => ['https://portal.test/informes', true],
    'same host in another case' => ['https://PORTAL.test/informes', true],
    'another host' => ['https://evil.test/informes', false],
    'host that only starts the same' => ['https://portal.test.evil.test/', false],
    'protocol relative' => ['//evil.test/informes', false],
    'backslash trick' => ['/\evil.test', false],
    'another scheme' => ['http://portal.test/informes', false],
    'another port' => ['https://portal.test:8443/informes', false],
    'javascript' => ['javascript:alert(1)', false],
    'relative without slash' => ['informes', false],
]);

test('without a login module the auth module is required', function () {
    TestCase::$bootConfig = ['ubo-common.modules.auth' => false];
    $this->refreshApplication();

    app(LoginSettings::class);
})->throws(LogicException::class, 'needs the auth module');
