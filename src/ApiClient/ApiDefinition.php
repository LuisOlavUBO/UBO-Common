<?php

namespace UBO\Common\ApiClient;

use InvalidArgumentException;
use UBO\Common\Support\ConfigValue;

/**
 * The API being called: its name in API-Auth, where it lives, and its values from
 * config/ubo-apis.php: "apis.<name>" first, then "defaults", then the package's.
 */
final readonly class ApiDefinition
{
    // Package defaults, used when neither "apis.<name>" nor "defaults" has a valid value.
    private const TIMEOUT = 10;

    private const CONNECT_TIMEOUT = 5;

    private const DEADLINE = 30;

    private const RETRIES = 2;

    private const RETRY_DELAY = 200;

    private const RETRY_MAX_DELAY = 2000;

    private const CIRCUIT_FAILURES = 5;

    private const CIRCUIT_COOLDOWN = 30;

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
        public int $deadline = self::DEADLINE,
        public int $retryMaxDelay = self::RETRY_MAX_DELAY,
        public bool $circuitEnabled = true,
        public int $circuitFailures = self::CIRCUIT_FAILURES,
        public int $circuitCooldown = self::CIRCUIT_COOLDOWN,
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

        $own = (array) ($config['apis'][$name] ?? []);
        $defaults = (array) ($config['defaults'] ?? []);
        $ownCircuit = (array) ($own['circuit_breaker'] ?? []);
        $defaultCircuit = (array) ($defaults['circuit_breaker'] ?? []);

        $positive = fn (string $key, int $package) => ConfigValue::positiveInt(
            $own[$key] ?? null,
            ConfigValue::positiveInt($defaults[$key] ?? null, $package),
        );
        $nonNegative = fn (string $key, int $package) => ConfigValue::nonNegativeInt(
            $own[$key] ?? null,
            ConfigValue::nonNegativeInt($defaults[$key] ?? null, $package),
        );
        $circuit = fn (string $key, int $package) => ConfigValue::positiveInt(
            $ownCircuit[$key] ?? null,
            ConfigValue::positiveInt($defaultCircuit[$key] ?? null, $package),
        );

        return new self(
            name: $name,
            url: rtrim($url, '/'),
            timeout: $positive('timeout', self::TIMEOUT),
            connectTimeout: $positive('connect_timeout', self::CONNECT_TIMEOUT),
            retries: $nonNegative('retries', self::RETRIES),
            retryDelay: $nonNegative('retry_delay', self::RETRY_DELAY),
            headers: (array) ($own['headers'] ?? $defaults['headers'] ?? []),
            deadline: $positive('deadline', self::DEADLINE),
            retryMaxDelay: $nonNegative('retry_max_delay', self::RETRY_MAX_DELAY),
            circuitEnabled: self::flag($ownCircuit['enabled'] ?? null) ?? self::flag($defaultCircuit['enabled'] ?? null) ?? true,
            circuitFailures: $circuit('failures', self::CIRCUIT_FAILURES),
            circuitCooldown: $circuit('cooldown', self::CIRCUIT_COOLDOWN),
        );
    }

    /**
     * Wait before retry number $retry (1 = the first retry), in milliseconds: exponential,
     * capped at $retryMaxDelay, and spread at random between half of it and all of it so
     * callers retrying the same failure do not hit the API at the same moment.
     */
    public function retryWait(int $retry): int
    {
        $wait = (int) min($this->retryMaxDelay, $this->retryDelay * 2 ** max(0, $retry - 1));

        return $wait > 0 ? random_int(intdiv($wait, 2), $wait) : 0;
    }

    private static function flag(mixed $value): ?bool
    {
        return $value === null || $value === '' ? null : filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }
}
