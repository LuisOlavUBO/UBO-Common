<?php

use Illuminate\Routing\Router;
use UBO\Common\Auth\AuthSettings;
use UBO\Common\SqlServer\SqlServerServiceProvider;
use UBO\Common\Tests\TestCase;

// TestCase::$bootConfig is set before the package registers, like a published config file
// holding only these keys. Dotted keys up to the leaves, because TestCase applies them again
// after registering, and a whole array there would overwrite the merged result.

test('a published config from an older version gets the nested keys added since', function () {
    TestCase::$bootConfig = [
        'ubo-auth.cache.prefix' => 'app:',
        'ubo-auth.cache.public_key_ttl' => 3600,
        'ubo-auth.cache.introspection_ttl' => 15,
    ];
    $this->refreshApplication();

    expect(config('ubo-auth.cache'))->toMatchArray([
        'prefix' => 'app:',
        'public_key_ttl' => 3600,
        'introspection_ttl' => 15,
        'service_token_margin' => 60,
    ])->and(config('ubo-auth.strategy'))->toBe('hybrid');
});

test('a nested value the application customised is kept', function () {
    TestCase::$bootConfig = ['ubo-apis.defaults.timeout' => 30];
    $this->refreshApplication();

    expect(config('ubo-apis.defaults.timeout'))->toBe(30)
        ->and(config('ubo-apis.defaults.retries'))->toBe(2)
        ->and(config('ubo-apis.defaults.connect_timeout'))->toBe(5);
});

test('a cached configuration is used as it is, without merging', function () {
    // Testbench never loads the cached file's contents, but configurationIsCached() sees it: the
    // package defaults must then not be merged, so ubo-auth only holds what TestCase sets.
    $cached = app()->getCachedConfigPath();
    file_put_contents($cached, '<?php return [];');

    try {
        $this->refreshApplication();

        expect(app()->configurationIsCached())->toBeTrue()
            ->and(config('ubo-auth.cache'))->toBeNull()
            ->and(config('ubo-auth.strategy'))->toBeNull()
            ->and(config('ubo-auth.url'))->toBe('https://api-auth.test');
    } finally {
        unlink($cached);
    }
});

test('the package boots without any auth configuration, as in a build without .env', function () {
    TestCase::$bootConfig = [
        'ubo-auth.url' => null,
        'ubo-auth.client_id' => null,
        'ubo-auth.client_secret' => null,
        'ubo-auth.strategy' => '',
        'ubo-auth.timeout' => '',
    ];
    $this->refreshApplication();

    expect(app(Router::class)->getMiddleware())->toHaveKey('ubo.auth')
        ->and(app(AuthSettings::class)->url)->toBeNull()
        ->and(app(AuthSettings::class)->timeout)->toBe(5);
});

test('a published ubo-common without a module switch gets the package default for it', function () {
    TestCase::$bootConfig = ['ubo-common.modules.auth' => true];
    $this->refreshApplication();

    expect(config('ubo-common.modules.sqlserver'))->toBeFalse()
        ->and(config('ubo-common.modules.tracing'))->toBeTrue()
        ->and(app()->getProviders(SqlServerServiceProvider::class))->toBe([]);
});
