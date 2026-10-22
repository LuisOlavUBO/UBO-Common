<?php

namespace UBO\Common\Auth\Infrastructure\Validators;

use DateTimeImmutable;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Psr\Clock\ClockInterface;
use UBO\Common\Auth\AuthSettings;
use UBO\Common\Auth\Contracts\ServiceTokenProvider;
use UBO\Common\Auth\Contracts\TokenValidator;
use UBO\Common\Auth\Domain\VerifiedToken;
use UBO\Common\Auth\Exceptions\AuthServerUnavailable;
use UBO\Common\Auth\Exceptions\InvalidToken;
use UBO\Common\Auth\Exceptions\ServiceTokenException;

/**
 * Asks API-Auth whether a token is active (POST /api/v1/auth/introspect), authenticating
 * with this application's own token (scope "introspect"). Results are cached for
 * ubo-auth.cache.introspection_ttl seconds, which bounds how late a revocation is noticed.
 */
final class IntrospectionValidator implements TokenValidator
{
    public const SCOPE = 'introspect';

    private readonly Repository $cache;

    public function __construct(
        private readonly AuthSettings $settings,
        private readonly HttpFactory $http,
        private readonly ServiceTokenProvider $serviceTokens,
        private readonly ClockInterface $clock,
        CacheFactory $cache,
    ) {
        $this->cache = $cache->store($settings->cacheStore);
    }

    public function validate(string $token): VerifiedToken
    {
        if ($token === '') {
            throw InvalidToken::missing();
        }

        $key = $this->settings->cachePrefix.'introspection:'.hash('sha256', $token);
        $claims = $this->cache->get($key);

        if (! is_array($claims)) {
            $claims = $this->introspect($token);
            $this->cache->put($key, $claims, $this->ttl($claims));
        }

        if (! $claims['active']) {
            throw InvalidToken::inactive();
        }

        return new VerifiedToken(
            id: 'sha256:'.substr(hash('sha256', $token), 0, 32),
            clientId: $claims['client_id'],
            userId: $claims['sub'],
            scopes: $claims['scopes'],
            expiresAt: (new DateTimeImmutable)->setTimestamp($claims['exp']),
            audiences: $claims['aud'] ?? [],
        );
    }

    /**
     * @return array{active: bool, client_id: string, sub: string|null, scopes: list<string>, aud: list<string>, exp: int}
     */
    private function introspect(string $token): array
    {
        $url = $this->settings->endpoint('introspect')
            ?? throw new AuthServerUnavailable('UBO_AUTH_URL is not configured.');

        try {
            $response = $this->send($url, $token);

            // Our own token may have been revoked or expired early: renew it once.
            if ($response->status() === 401) {
                $this->serviceTokens->forget([self::SCOPE]);
                $response = $this->send($url, $token);
            }
        } catch (ConnectionException $e) {
            throw new AuthServerUnavailable('Could not reach API-Auth introspection.', $e);
        } catch (ServiceTokenException $e) {
            throw new AuthServerUnavailable('Could not authenticate against API-Auth: '.$e->getMessage(), $e);
        }

        if (! $response->successful()) {
            throw new AuthServerUnavailable("API-Auth introspection answered {$response->status()}.");
        }

        $data = $response->json();

        if (($data['active'] ?? false) !== true || ! is_string($data['client_id'] ?? null) || ! is_numeric($data['exp'] ?? null)) {
            return ['active' => false, 'client_id' => '', 'sub' => null, 'scopes' => [], 'aud' => [], 'exp' => 0];
        }

        return [
            'active' => true,
            'client_id' => $data['client_id'],
            'sub' => VerifiedToken::userIdFromSubject($data['sub'] ?? null, $data['client_id']),
            'scopes' => array_values(array_filter(explode(' ', (string) ($data['scope'] ?? '')))),
            'aud' => array_values(array_filter((array) ($data['aud'] ?? []), 'is_string')),
            'exp' => (int) $data['exp'],
        ];
    }

    private function send(string $url, string $token): Response
    {
        return $this->http
            ->timeout($this->settings->timeout)
            ->acceptJson()
            ->withToken($this->serviceTokens->token([self::SCOPE]))
            ->post($url, ['token' => $token]);
    }

    /**
     * Active results are kept at most until the token expires.
     *
     * @param  array{active: bool, exp: int}  $claims
     */
    private function ttl(array $claims): int
    {
        if (! $claims['active']) {
            return max(1, $this->settings->introspectionTtl);
        }

        $remaining = $claims['exp'] - $this->clock->now()->getTimestamp();

        return max(1, min($this->settings->introspectionTtl, $remaining));
    }
}
