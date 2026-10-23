<?php

namespace UBO\Common\Problems;

/**
 * Typed view of config/ubo-problems.php, so the module never reads raw config keys.
 */
final readonly class ProblemsSettings
{
    /**
     * @param  list<string>  $routes
     */
    public function __construct(
        public array $routes,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(array $config): self
    {
        $routes = $config['routes'] ?? 'api/*';

        if (is_string($routes)) {
            $routes = explode(',', $routes);
        }

        return new self(
            routes: array_values(array_filter(array_map(fn ($route) => trim((string) $route), (array) $routes), 'strlen')),
        );
    }
}
