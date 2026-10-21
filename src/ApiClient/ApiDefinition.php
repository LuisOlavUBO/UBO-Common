<?php

namespace UBO\Common\ApiClient;

use InvalidArgumentException;

/**
 * The API being called: its name in API-Auth, where it lives, and the defaults from
 * config/ubo-apis.php.
 */
final readonly class ApiDefinition
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public string $name,
        public string $url,
        public int $timeout,
        public int $connectTimeout,
        public int $retries,
        public int $retryDelay,
        public array $headers,
    ) {}

    /**
     * @param  array<string, mixed>  $config  the whole ubo-apis config
     */
    public static function fromConfig(string $name, ?string $url, array $config): self
    {
        if (blank($name)) {
            throw new InvalidArgumentException('The API name (as registered in API-Auth, e.g. "api-maestros") is required.');
        }

        if (blank($url)) {
            throw new InvalidArgumentException("API [{$name}] was called without a URL. Check the variable that holds it in this application's .env.");
        }

        $defaults = (array) ($config['defaults'] ?? []);

        return new self(
            name: $name,
            url: rtrim($url, '/'),
            timeout: (int) ($defaults['timeout'] ?? 10),
            connectTimeout: (int) ($defaults['connect_timeout'] ?? 5),
            retries: max(0, (int) ($defaults['retries'] ?? 0)),
            retryDelay: max(0, (int) ($defaults['retry_delay'] ?? 0)),
            headers: (array) ($defaults['headers'] ?? []),
        );
    }
}
