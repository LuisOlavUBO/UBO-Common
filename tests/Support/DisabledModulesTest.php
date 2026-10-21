<?php

use Illuminate\Routing\Router;
use UBO\Common\ApiClient\ApiClientManager;
use UBO\Common\ApiClient\UboApi;
use UBO\Common\Tests\TestCase;

test('a disabled module is not registered', function () {
    TestCase::$bootConfig = ['ubo-common.modules.auth' => false];
    $this->refreshApplication();

    expect(app(Router::class)->getMiddleware())->not->toHaveKey('ubo.auth')
        ->and(app()->bound(ApiClientManager::class))->toBeTrue();
});

test('the api-client module explains that it needs the auth module', function () {
    TestCase::$bootConfig = ['ubo-common.modules.auth' => false];
    $this->refreshApplication();

    UboApi::call('api-maestros', 'https://maestros.test');
})->throws(LogicException::class, 'needs the auth module');
