<?php

namespace UBO\Common;

use Illuminate\Support\ServiceProvider;
use UBO\Common\ApiClient\ApiClientServiceProvider;
use UBO\Common\Auth\AuthServiceProvider;
use UBO\Common\Health\HealthServiceProvider;
use UBO\Common\Problems\ProblemsServiceProvider;
use UBO\Common\SqlServer\SqlServerServiceProvider;
use UBO\Common\Support\ModuleServiceProvider;
use UBO\Common\Tracing\TracingServiceProvider;

/**
 * Package entry point (auto-discovered by Laravel). Registers only the modules
 * enabled in config/ubo-common.php.
 */
final class UboCommonServiceProvider extends ServiceProvider
{
    /**
     * Module catalog: config key => module service provider.
     * To add a module, create src/<Module> and register it here.
     *
     * @var array<string, class-string<ModuleServiceProvider>>
     */
    public const MODULES = [
        'auth' => AuthServiceProvider::class,
        'api-client' => ApiClientServiceProvider::class,
        'tracing' => TracingServiceProvider::class,
        'problems' => ProblemsServiceProvider::class,
        'health' => HealthServiceProvider::class,
        'sqlserver' => SqlServerServiceProvider::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ubo-common.php', 'ubo-common');

        foreach (self::MODULES as $key => $provider) {
            if ($this->app['config']->get("ubo-common.modules.{$key}", true)) {
                $this->app->register($provider);
            }
        }
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/ubo-common.php' => $this->app->configPath('ubo-common.php'),
            ], 'ubo-common-config');
        }
    }
}
