<?php

namespace UBO\Common\Support;

use Illuminate\Support\ServiceProvider;

/**
 * Base class for every UBO.Common module. It merges (deeply) and publishes the module's
 * config file so each module only declares what is specific to it.
 *
 * A module lives in src/<Module>/ with its config at src/<Module>/config/<configName>.php
 * and can be published with: php artisan vendor:publish --tag=<configName>-config
 */
abstract class ModuleServiceProvider extends ServiceProvider
{
    use MergesConfigDeeply;

    /** Config key and file name, e.g. "ubo-auth". */
    abstract protected function configName(): string;

    /** Absolute path of the module's default config file. */
    abstract protected function configPath(): string;

    /** Container bindings of the module. */
    protected function registerModule(): void {}

    /** Routes, middleware aliases, commands... of the module. */
    protected function bootModule(): void {}

    final public function register(): void
    {
        $this->mergeConfigDeeplyFrom($this->configPath(), $this->configName());

        $this->registerModule();
    }

    final public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                $this->configPath() => $this->app->configPath($this->configName().'.php'),
            ], $this->configName().'-config');
        }

        $this->bootModule();
    }
}
