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

test('the session of the login has defaults when nothing is configured', function () {
    $settings = loginSettings([]);

    expect($settings->store)->toBeNull()
        ->and($settings->refreshMargin)->toBe(60)
        ->and($settings->revalidate)->toBe(300)
        ->and($settings->maxAge)->toBe(43200)
        ->and($settings->refreshTtl)->toBe(7200);
});

test('empty values of the session of the login use the defaults', function () {
    $settings = loginSettings(['store' => '', 'refresh_margin' => '', 'revalidate' => '', 'max_age' => '', 'refresh_ttl' => '']);

    expect($settings->store)->toBeNull()
        ->and($settings->refreshMargin)->toBe(60)
        ->and($settings->revalidate)->toBe(300)
        ->and($settings->maxAge)->toBe(43200)
        ->and($settings->refreshTtl)->toBe(7200);
});

test('values of the session of the login are read from strings, as the .env gives them', function () {
    $settings = loginSettings(['store' => 'redis', 'refresh_margin' => '120', 'revalidate' => '60', 'max_age' => '3600', 'refresh_ttl' => '1800']);

    expect($settings->store)->toBe('redis')
        ->and($settings->refreshMargin)->toBe(120)
        ->and($settings->revalidate)->toBe(60)
        ->and($settings->maxAge)->toBe(3600)
        ->and($settings->refreshTtl)->toBe(1800);
});

test('zero turns off the revalidation and the limit of the session', function () {
    $settings = loginSettings(['revalidate' => '0', 'max_age' => 0]);

    expect($settings->revalidate)->toBe(0)
        ->and($settings->maxAge)->toBe(0);
});

test('a zero margin or time to live uses the default', function () {
    $settings = loginSettings(['refresh_margin' => 0, 'refresh_ttl' => '0']);

    expect($settings->refreshMargin)->toBe(60)
        ->and($settings->refreshTtl)->toBe(7200);
});

test('non numeric or negative values of the session of the login use the defaults', function (mixed $value) {
    $settings = loginSettings(['refresh_margin' => $value, 'revalidate' => $value, 'max_age' => $value, 'refresh_ttl' => $value]);

    expect($settings->refreshMargin)->toBe(60)
        ->and($settings->revalidate)->toBe(300)
        ->and($settings->maxAge)->toBe(43200)
        ->and($settings->refreshTtl)->toBe(7200);
})->with(['soon', -5, null]);

test('the settings of the session of the login come from the config file', function () {
    config(['ubo-login.store' => 'login', 'ubo-login.max_age' => 0]);

    expect(app(LoginSettings::class)->store)->toBe('login')
        ->and(app(LoginSettings::class)->maxAge)->toBe(0)
        ->and(config('ubo-login.refresh_margin'))->toBe(60)
        ->and(config('ubo-login.revalidate'))->toBe(300)
        ->and(config('ubo-login.refresh_ttl'))->toBe(7200);
});
