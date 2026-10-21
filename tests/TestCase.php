<?php

namespace UBO\Common\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use UBO\Common\UboCommonServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * Config applied before the package registers, for tests that need to boot the
     * application differently (call $this->refreshApplication() after setting it).
     *
     * @var array<string, mixed>
     */
    public static array $bootConfig = [];

    protected function getPackageProviders($app): array
    {
        return [UboCommonServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('ubo-auth.url', 'https://api-auth.test');
        $app['config']->set('ubo-auth.client_id', 'maestros-client');
        $app['config']->set('ubo-auth.client_secret', 'maestros-secret');
        $app['config']->set('ubo-apis.defaults.retry_delay', 0);

        foreach (static::$bootConfig as $key => $value) {
            $app['config']->set($key, $value);
        }
    }

    /**
     * defineEnvironment() runs after providers register; module switches must be set before.
     */
    protected function resolveApplicationConfiguration($app)
    {
        parent::resolveApplicationConfiguration($app);

        foreach (static::$bootConfig as $key => $value) {
            $app['config']->set($key, $value);
        }
    }

    protected function tearDown(): void
    {
        static::$bootConfig = [];

        parent::tearDown();
    }
}
