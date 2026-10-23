<?php

use Illuminate\Routing\Router;
use UBO\Common\ApiClient\ApiClientManager;
use UBO\Common\Auth\Contracts\TokenValidator;
use UBO\Common\Auth\Infrastructure\Validators\HybridValidator;
use UBO\Common\Auth\Infrastructure\Validators\IntrospectionValidator;
use UBO\Common\Auth\Infrastructure\Validators\JwtValidator;
use UBO\Common\Tracing\Contracts\TraceContext;

test('the passive modules are enabled and sqlserver is off by default', function () {
    expect(config('ubo-problems.routes'))->toBe('api/*')
        ->and(config()->has('ubo-health.connection'))->toBeTrue()
        ->and(config('ubo-common.modules.sqlserver'))->toBeFalse()
        ->and(config('ubo-sqlserver'))->toBeNull();
});

test('every module is enabled by default', function () {
    expect(app(Router::class)->getMiddleware())->toHaveKey('ubo.auth')
        ->and(app()->bound(ApiClientManager::class))->toBeTrue()
        ->and(app()->bound(TraceContext::class))->toBeTrue()
        ->and(config('ubo-tracing.response_header'))->toBe('X-Trace-Id')
        ->and(config('ubo-auth.strategy'))->toBe('hybrid')
        ->and(config('ubo-apis.defaults.retries'))->toBe(2);
});

test('the strategy selects the validator', function (string $strategy, string $class) {
    config(['ubo-auth.strategy' => $strategy]);

    expect(app(TokenValidator::class))->toBeInstanceOf($class);
})->with([
    ['jwt', JwtValidator::class],
    ['introspection', IntrospectionValidator::class],
    ['hybrid', HybridValidator::class],
]);

test('an unknown strategy fails loudly', function () {
    config(['ubo-auth.strategy' => 'magic']);

    app(TokenValidator::class);
})->throws(InvalidArgumentException::class);

test('the module config files can be published', function () {
    $this->artisan('vendor:publish', ['--tag' => 'ubo-auth-config', '--force' => true])->assertSuccessful();

    expect(config_path('ubo-auth.php'))->toBeFile();

    unlink(config_path('ubo-auth.php'));
});
