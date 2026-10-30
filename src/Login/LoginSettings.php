<?php

namespace UBO\Common\Login;

use UBO\Common\Auth\AuthSettings;
use UBO\Common\Login\Exceptions\LoginNotConfigured;
use UBO\Common\Support\ConfigValue;

/**
 * Typed view of config/ubo-login.php, so the module never reads raw config keys.
 */
final readonly class LoginSettings
{
    private const ROUTE_PREFIX = 'iam';

    private const HOME = '/';

    private const STATE_TTL = 600;

    public function __construct(
        public ?string $publicUrl,
        public ?string $clientId,
        public ?string $clientSecret,
        public ?string $redirectUri,
        public string $routePrefix,
        public string $home,
        public ?string $logoutReturn,
        public ?string $prompt,
        public int $stateTtl,
        public ?string $appUrl,
    ) {}

    /**
     * @param  array<string, mixed>  $config  the ubo-login config
     */
    public static function fromConfig(array $config, AuthSettings $auth, ?string $appUrl): self
    {
        $publicUrl = filled($config['public_url'] ?? null) ? (string) $config['public_url'] : $auth->url;

        return new self(
            publicUrl: $publicUrl === null ? null : rtrim($publicUrl, '/'),
            clientId: filled($config['client_id'] ?? null) ? (string) $config['client_id'] : null,
            clientSecret: filled($config['client_secret'] ?? null) ? (string) $config['client_secret'] : null,
            redirectUri: filled($config['redirect_uri'] ?? null) ? (string) $config['redirect_uri'] : null,
            routePrefix: self::routePrefixFrom($config),
            home: ConfigValue::filledString($config['home'] ?? null, self::HOME),
            logoutReturn: filled($config['logout_return'] ?? null) ? (string) $config['logout_return'] : null,
            prompt: ($config['prompt'] ?? null) === 'login' ? 'login' : null,
            stateTtl: ConfigValue::positiveInt($config['state_ttl'] ?? null, self::STATE_TTL),
            appUrl: filled($appUrl) ? rtrim((string) $appUrl, '/') : null,
        );
    }

    /**
     * Prefix of the module's routes, read without the rest of the settings: the routes are
     * registered when the module boots, before anything is checked.
     *
     * @param  array<string, mixed>  $config  the ubo-login config
     */
    public static function routePrefixFrom(array $config): string
    {
        return trim(ConfigValue::filledString($config['route_prefix'] ?? null, self::ROUTE_PREFIX), '/');
    }

    /**
     * The login client's id, for the routes that send the person to IAM.
     *
     * @throws LoginNotConfigured
     */
    public function clientId(): string
    {
        return $this->clientId ?? throw LoginNotConfigured::missing('UBO_LOGIN_CLIENT_ID');
    }

    /**
     * @throws LoginNotConfigured
     */
    public function clientSecret(): string
    {
        return $this->clientSecret ?? throw LoginNotConfigured::missing('UBO_LOGIN_CLIENT_SECRET');
    }

    /** IAM's authorization endpoint, as the browser reaches it. */
    public function authorizeUrl(): string
    {
        return $this->publicUrl().'/api/auth/oauth/authorize';
    }

    /** IAM's central logout, as the browser reaches it. */
    public function logoutUrl(): string
    {
        return $this->publicUrl().'/api/auth/logout';
    }

    /**
     * The redirect URI of the callback: the configured one or the route under APP_URL. It is
     * never taken from the request, whose Host header the caller controls.
     */
    public function redirectUri(): string
    {
        return $this->redirectUri ?? $this->absolute('/'.$this->routePrefix.'/callback');
    }

    /** Where IAM sends the person after the central logout. */
    public function logoutReturnUrl(): string
    {
        return $this->logoutReturn ?? $this->absolute($this->home);
    }

    /**
     * The absolute URL of a path of this application.
     */
    public function absolute(string $path): string
    {
        if (preg_match('#^https?://#i', $path) === 1) {
            return $path;
        }

        return ($this->appUrl ?? throw LoginNotConfigured::missing('APP_URL')).'/'.ltrim($path, '/');
    }

    /**
     * Whether a destination belongs to this application: a path, or an address on APP_URL's host.
     */
    public function isLocal(string $destination): bool
    {
        if (str_contains($destination, '\\')) {
            return false;
        }

        if (str_starts_with($destination, '/')) {
            return ! str_starts_with($destination, '//');
        }

        $parts = parse_url($destination);
        $app = $this->appUrl === null ? null : parse_url($this->appUrl);

        return $app !== null
            && isset($parts['scheme'], $parts['host'], $app['scheme'], $app['host'])
            && in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            && strcasecmp($parts['scheme'], $app['scheme']) === 0
            && strcasecmp($parts['host'], $app['host']) === 0
            && ($parts['port'] ?? null) === ($app['port'] ?? null);
    }

    private function publicUrl(): string
    {
        return $this->publicUrl ?? throw LoginNotConfigured::missing('UBO_AUTH_URL');
    }
}
