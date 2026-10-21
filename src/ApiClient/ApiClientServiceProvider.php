<?php

namespace UBO\Common\ApiClient;

use UBO\Common\Support\ModuleServiceProvider;

/**
 * ApiClient module: calls UBO APIs with a token from API-Auth handled automatically.
 * Depends on the Auth module's ServiceTokenProvider contract.
 */
final class ApiClientServiceProvider extends ModuleServiceProvider
{
    protected function configName(): string
    {
        return 'ubo-apis';
    }

    protected function configPath(): string
    {
        return __DIR__.'/config/ubo-apis.php';
    }

    protected function registerModule(): void
    {
        $this->app->singleton(ApiClientManager::class);
    }
}
