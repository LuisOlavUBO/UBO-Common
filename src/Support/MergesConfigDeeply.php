<?php

namespace UBO\Common\Support;

use Illuminate\Contracts\Foundation\CachesConfiguration;

/**
 * mergeConfigFrom() for service providers, but deep: a config file published with an older
 * version still gets the nested keys added since (see ConfigMerge).
 *
 * @internal
 */
trait MergesConfigDeeply
{
    protected function mergeConfigDeeplyFrom(string $path, string $key): void
    {
        // Like mergeConfigFrom(): a cached configuration is used as it is.
        if ($this->app instanceof CachesConfiguration && $this->app->configurationIsCached()) {
            return;
        }

        $config = $this->app->make('config');

        $config->set($key, ConfigMerge::deep(require $path, (array) $config->get($key, [])));
    }
}
