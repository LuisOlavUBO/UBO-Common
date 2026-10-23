<?php

namespace UBO\Common\Health;

use UBO\Common\Support\ModuleServiceProvider;

/**
 * Health module: the health endpoint every UBO API exposes for orchestrators and load
 * balancers. Passive: it only loads its config; the application adds the route,
 * Route::get('v1/salud', HealthController::class).
 */
final class HealthServiceProvider extends ModuleServiceProvider
{
    protected function configName(): string
    {
        return 'ubo-health';
    }

    protected function configPath(): string
    {
        return __DIR__.'/config/ubo-health.php';
    }
}
