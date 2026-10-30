<?php

namespace UBO\Common\Auth\Infrastructure;

use Closure;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Client\Response;
use Psr\Clock\ClockInterface;
use UBO\Common\Auth\AuthSettings;
use UBO\Common\Auth\Contracts\ServiceTokenProvider;
use UBO\Common\Auth\Exceptions\ServiceTokenException;

/**
 * Client credentials grant against API-Auth (POST /api/auth/oauth/token). One cached token per
 * API and set of scopes, renewed shortly before it expires. A cache lock keeps
 * concurrent workers from requesting the same token at once.
 *
 * The cached token is kept until it really expires: if API-Auth does not answer when it is
 * time to renew it, the token still in force is used instead of failing.
 */
final class HttpServiceTokenProvider implements ServiceTokenProvider
{
    private readonly Repository $cache;

    public function __construct(
        private readonly AuthSettings $settings,
        private readonly ApiAuthClient $apiAuth,
        private readonly ClockInterface $clock,
        CacheFactory $cache,
    ) {
        $this->cache = $cache->store($settings->cacheStore);
    }

    public function token(array $scopes = [], ?string $api = null): string
    {
        if (! $this->settings->hasClientCredentials()) {
            throw ServiceTokenException::notConfigured();
        }

        $scopes = $this->normalize($scopes);
        $key = $this->key($scopes, $api);

        if (($token = $this->cached($key, renewAfter: true)) !== null) {
            return $token;
        }

        return $this->locked($key, function () use ($key, $scopes, $api) {
            // Another worker may have fetched it while we waited for the lock.
            if (($token = $this->cached($key, renewAfter: true)) !== null) {
                return $token;
            }

            try {
                [$token, $expiresIn] = $this->request($scopes, $api);
            } catch (ApiAuthUnreachable $e) {
                // API-Auth did not answer: the cached token still works until it expires.
                return $this->cached($key, renewAfter: false) ?? throw $this->unreachable($e);
            }

            $now = $this->clock->now()->getTimestamp();

            $this->cache->put($key, [
                'token' => $token,
                'refresh_at' => $now + max(1, $expiresIn - $this->settings->serviceTokenMargin),
                'expires_at' => $now + $expiresIn,
            ], max(1, $expiresIn));

            return $token;
        });
    }

    public function forget(array $scopes = [], ?string $api = null): void
    {
        $this->cache->forget($this->key($this->normalize($scopes), $api));
    }

    /**
     * The cached token, while it is not due for renewal ($renewAfter) or, as a fallback,
     * while it has not expired. A plain string was cached by an older version, with a
     * TTL that already ends before its renewal time.
     */
    private function cached(string $key, bool $renewAfter): ?string
    {
        $entry = $this->cache->get($key);

        if (is_string($entry)) {
            return $entry;
        }

        if (! is_array($entry) || ! is_string($entry['token'] ?? null)) {
            return null;
        }

        $until = $renewAfter ? ($entry['refresh_at'] ?? 0) : ($entry['expires_at'] ?? 0);

        return $this->clock->now()->getTimestamp() < $until ? $entry['token'] : null;
    }

    /**
     * @param  list<string>  $scopes
     * @return array{0: string, 1: int} token and lifetime in seconds
     *
     * @throws ApiAuthUnreachable
     */
    private function request(array $scopes, ?string $api): array
    {
        $response = $this->apiAuth->send(fn ($request) => $request
            ->acceptJson()
            ->asForm()
            ->post($this->settings->endpoint('token'), array_filter([
                'grant_type' => 'client_credentials',
                'client_id' => $this->settings->clientId,
                'client_secret' => $this->settings->clientSecret,
                'scope' => implode(' ', $scopes),
                'api' => $api,
            ], fn (?string $value) => $value !== null && $value !== '')));

        $token = $response->json('access_token');

        if (! $response->successful() || ! is_string($token) || $token === '') {
            throw $this->rejected($response);
        }

        return [$token, (int) ($response->json('expires_in') ?? 3600)];
    }

    private function unreachable(ApiAuthUnreachable $e): ServiceTokenException
    {
        return match (true) {
            $e->response !== null => $this->rejected($e->response),
            $e->isCircuitOpen() => ServiceTokenException::unavailable($e),
            default => ServiceTokenException::unreachable($e->getPrevious() ?? $e),
        };
    }

    private function rejected(Response $response): ServiceTokenException
    {
        // league/oauth2-server puts the useful part (e.g. which scope) in "hint".
        $description = $response->json('error_description') ?? $response->json('message');
        $hint = $response->json('hint');

        if (is_string($hint) && $hint !== '') {
            $description = $description ? rtrim($description, '.').". {$hint}." : "{$hint}.";
        }

        return ServiceTokenException::rejected($response->status(), $response->json('error'), $description);
    }

    private function locked(string $key, Closure $callback): string
    {
        if (! $this->cache->getStore() instanceof LockProvider) {
            return $callback();
        }

        try {
            return $this->cache->lock($key.':lock', 10)->block(5, $callback);
        } catch (LockTimeoutException) {
            return $callback();
        }
    }

    /**
     * @param  list<string>  $scopes
     * @return list<string>
     */
    private function normalize(array $scopes): array
    {
        $scopes = array_values(array_unique(array_filter($scopes)));
        sort($scopes);

        return $scopes;
    }

    /**
     * @param  list<string>  $scopes
     */
    private function key(array $scopes, ?string $api): string
    {
        return $this->settings->cachePrefix.'service-token:'.sha1($this->settings->clientId.'|'.$api.'|'.implode(' ', $scopes));
    }
}
