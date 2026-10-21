<?php

namespace UBO\Common\ApiClient\Exceptions;

use InvalidArgumentException;

/**
 * The API name is not in config/ubo-apis.php, or it has no URL configured.
 */
final class UnknownApi extends InvalidArgumentException
{
    /**
     * @param  list<string>  $known
     */
    public static function named(string $name, array $known): self
    {
        return new self("API [{$name}] is not defined in config/ubo-apis.php. Known APIs: ".(implode(', ', $known) ?: 'none').'.');
    }

    public static function withoutUrl(string $name): self
    {
        $env = 'UBO_API_'.strtoupper(str_replace('-', '_', $name)).'_URL';

        return new self("API [{$name}] has no URL configured (usually {$env}).");
    }
}
