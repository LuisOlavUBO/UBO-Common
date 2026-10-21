<?php

namespace UBO\Common\ApiClient;

use UBO\Common\ApiClient\Exceptions\UnknownApi;

/**
 * One entry of the config/ubo-apis.php catalog, with defaults applied.
 */
final readonly class ApiDefinition
{
    /**
     * @param  list<string>  $scopes
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public string $name,
        public string $url,
        public array $scopes,
        public int $timeout,
        public int $connectTimeout,
        public int $retries,
        public int $retryDelay,
        public array $headers,
    ) {}

    /**
     * @param  array<string, mixed>  $config  the whole ubo-apis config
     */
    public static function fromConfig(string $name, array $config): self
    {
        $api = $config['apis'][$name] ?? throw UnknownApi::named($name, array_keys($config['apis'] ?? []));
        $settings = array_replace($config['defaults'] ?? [], $api);

        if (blank($settings['url'] ?? null)) {
            throw UnknownApi::withoutUrl($name);
        }

        $scopes = $settings['scopes'] ?? [];

        return new self(
            name: $name,
            url: rtrim((string) $settings['url'], '/'),
            scopes: array_values(array_filter(is_array($scopes) ? $scopes : explode(' ', (string) $scopes))),
            timeout: (int) ($settings['timeout'] ?? 10),
            connectTimeout: (int) ($settings['connect_timeout'] ?? 5),
            retries: max(0, (int) ($settings['retries'] ?? 0)),
            retryDelay: max(0, (int) ($settings['retry_delay'] ?? 0)),
            headers: (array) ($settings['headers'] ?? []),
        );
    }
}
