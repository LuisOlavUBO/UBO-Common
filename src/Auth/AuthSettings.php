<?php

namespace UBO\Common\Auth;

/**
 * Typed view of config/ubo-auth.php, so the module never reads raw config keys.
 */
final readonly class AuthSettings
{
    /**
     * @param  array{token: string, introspect: string, public_key: string}  $endpoints
     */
    public function __construct(
        public ?string $url,
        public array $endpoints,
        public ?string $clientId,
        public ?string $clientSecret,
        public string $strategy,
        public bool $allowUserTokens,
        public ?string $api,
        public ?string $publicKey,
        public int $leeway,
        public ?string $cacheStore,
        public string $cachePrefix,
        public int $publicKeyTtl,
        public int $introspectionTtl,
        public int $serviceTokenMargin,
        public int $timeout,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(array $config): self
    {
        return new self(
            url: filled($config['url'] ?? null) ? rtrim($config['url'], '/') : null,
            endpoints: $config['endpoints'],
            clientId: filled($config['client_id'] ?? null) ? (string) $config['client_id'] : null,
            clientSecret: filled($config['client_secret'] ?? null) ? (string) $config['client_secret'] : null,
            strategy: (string) $config['strategy'],
            allowUserTokens: (bool) $config['allow_user_tokens'],
            api: filled($config['api'] ?? null) ? (string) $config['api'] : null,
            publicKey: filled($config['public_key'] ?? null) ? (string) $config['public_key'] : null,
            leeway: (int) $config['leeway'],
            cacheStore: $config['cache']['store'] ?? null,
            cachePrefix: (string) $config['cache']['prefix'],
            publicKeyTtl: (int) $config['cache']['public_key_ttl'],
            introspectionTtl: (int) $config['cache']['introspection_ttl'],
            serviceTokenMargin: (int) $config['cache']['service_token_margin'],
            timeout: (int) $config['timeout'],
        );
    }

    /** Absolute URL of an API-Auth endpoint ("token", "introspect" or "public_key"). */
    public function endpoint(string $name): ?string
    {
        return $this->url === null ? null : $this->url.$this->endpoints[$name];
    }

    public function hasClientCredentials(): bool
    {
        return $this->url !== null && $this->clientId !== null && $this->clientSecret !== null;
    }
}
