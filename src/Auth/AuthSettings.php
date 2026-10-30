<?php

namespace UBO\Common\Auth;

use UBO\Common\Support\ConfigValue;

/**
 * Typed view of config/ubo-auth.php, so the module never reads raw config keys.
 */
final readonly class AuthSettings
{
    /** API-Auth's routes, relative to its URL. Only UBO_AUTH_ENDPOINT_* can replace them. */
    private const ENDPOINTS = [
        'token' => '/api/auth/oauth/token',
        'introspect' => '/api/auth/v1/introspect',
        'public_key' => '/api/auth/v1/public-key',
        'logout' => '/api/auth/v1/logout',
    ];

    // Defaults when a value is missing, empty or out of range (same as config/ubo-auth.php).
    private const STRATEGY = 'hybrid';

    private const LEEWAY = 10;

    private const CACHE_PREFIX = 'ubo-auth:';

    private const PUBLIC_KEY_TTL = 86400;

    private const INTROSPECTION_TTL = 30;

    private const SERVICE_TOKEN_MARGIN = 60;

    private const TIMEOUT = 5;

    private const CONNECT_TIMEOUT = 2;

    private const CIRCUIT_FAILURES = 5;

    private const CIRCUIT_COOLDOWN = 30;

    /**
     * @param  array{token: string, introspect: string, public_key: string, logout: string}  $endpoints
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
        public int $connectTimeout = self::CONNECT_TIMEOUT,
        public int $circuitFailures = self::CIRCUIT_FAILURES,
        public int $circuitCooldown = self::CIRCUIT_COOLDOWN,
        public bool $degradedMode = false,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(array $config): self
    {
        $cache = (array) ($config['cache'] ?? []);
        $overrides = (array) ($config['endpoint_overrides'] ?? []);
        $circuit = (array) ($config['circuit_breaker'] ?? []);
        $timeout = ConfigValue::positiveInt($config['timeout'] ?? null, self::TIMEOUT);

        return new self(
            url: filled($config['url'] ?? null) ? rtrim($config['url'], '/') : null,
            endpoints: self::endpoints($overrides),
            clientId: filled($config['client_id'] ?? null) ? (string) $config['client_id'] : null,
            clientSecret: filled($config['client_secret'] ?? null) ? (string) $config['client_secret'] : null,
            strategy: ConfigValue::filledString($config['strategy'] ?? null, self::STRATEGY),
            allowUserTokens: (bool) ($config['allow_user_tokens'] ?? false),
            api: filled($config['api'] ?? null) ? (string) $config['api'] : null,
            publicKey: filled($config['public_key'] ?? null) ? (string) $config['public_key'] : null,
            leeway: ConfigValue::nonNegativeInt($config['leeway'] ?? null, self::LEEWAY),
            cacheStore: filled($cache['store'] ?? null) ? (string) $cache['store'] : null,
            cachePrefix: ConfigValue::filledString($cache['prefix'] ?? null, self::CACHE_PREFIX),
            publicKeyTtl: ConfigValue::positiveInt($cache['public_key_ttl'] ?? null, self::PUBLIC_KEY_TTL),
            introspectionTtl: ConfigValue::positiveInt($cache['introspection_ttl'] ?? null, self::INTROSPECTION_TTL),
            serviceTokenMargin: ConfigValue::positiveInt($cache['service_token_margin'] ?? null, self::SERVICE_TOKEN_MARGIN),
            timeout: $timeout,
            // Never longer than the whole call.
            connectTimeout: min($timeout, ConfigValue::positiveInt($config['connect_timeout'] ?? null, self::CONNECT_TIMEOUT)),
            circuitFailures: ConfigValue::positiveInt($circuit['failures'] ?? null, self::CIRCUIT_FAILURES),
            circuitCooldown: ConfigValue::positiveInt($circuit['cooldown'] ?? null, self::CIRCUIT_COOLDOWN),
            degradedMode: filter_var($config['degraded_mode'] ?? false, FILTER_VALIDATE_BOOLEAN),
        );
    }

    /**
     * The package's routes, each replaced by its UBO_AUTH_ENDPOINT_* override when set. A
     * published "endpoints" array is ignored, so the routes never freeze in an old file.
     *
     * @param  array<array-key, mixed>  $overrides
     * @return array{token: string, introspect: string, public_key: string, logout: string}
     */
    private static function endpoints(array $overrides): array
    {
        $endpoints = self::ENDPOINTS;

        foreach ($endpoints as $name => $path) {
            $endpoints[$name] = ConfigValue::filledString($overrides[$name] ?? null, $path);
        }

        return $endpoints;
    }

    /** Absolute URL of an API-Auth endpoint ("token", "introspect", "public_key" or "logout"). */
    public function endpoint(string $name): ?string
    {
        return $this->url === null ? null : $this->url.$this->endpoints[$name];
    }

    public function hasClientCredentials(): bool
    {
        return $this->url !== null && $this->clientId !== null && $this->clientSecret !== null;
    }
}
