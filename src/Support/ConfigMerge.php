<?php

namespace UBO\Common\Support;

/**
 * Deep merge of a package's default config with the application's (published) config.
 *
 * In associative arrays the application wins key by key, and keys it lacks come from the
 * package, at any depth. Lists (including empty arrays) are replaced whole, so a shorter
 * published list never inherits package items by index. A key the application sets,
 * even to null, keeps the application's value.
 *
 * @internal
 */
final class ConfigMerge
{
    /**
     * @param  array<array-key, mixed>  $package
     * @param  array<array-key, mixed>  $app
     * @return array<array-key, mixed>
     */
    public static function deep(array $package, array $app): array
    {
        foreach ($app as $key => $value) {
            $default = $package[$key] ?? null;

            $package[$key] = is_array($value) && is_array($default) && ! array_is_list($value) && ! array_is_list($default)
                ? self::deep($default, $value)
                : $value;
        }

        return $package;
    }
}
