<?php

namespace UBO\Common\Auth\Infrastructure;

use Closure;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use UBO\Common\Auth\AuthSettings;
use UBO\Common\Auth\Contracts\ServiceTokenProvider;
use UBO\Common\Auth\Exceptions\ServiceTokenException;
use UBO\Common\Tracing\Contracts\TraceContext;

/**
 * Client credentials grant against API-Auth (POST /api/auth/oauth/token). One cached token per
 * API and set of scopes, renewed shortly before it expires. A cache lock keeps
 * concurrent workers from requesting the same token at once.
 */
final class HttpServiceTokenProvider implements ServiceTokenProvider
{
    private readonly Repository $cache;

    public function __construct(
        private readonly AuthSettings $settings,
        private readonly HttpFactory $http,
        CacheFactory $cache,
        private readonly ?TraceContext $trace = null,
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

        if (is_string($token = $this->cache->get($key))) {
            return $token;
        }

        return $this->locked($key, function () use ($key, $scopes, $api) {
            // Another worker may have fetched it while we waited for the lock.
            if (is_string($token = $this->cache->get($key))) {
                return $token;
            }

            [$token, $expiresIn] = $this->request($scopes, $api);
            $this->cache->put($key, $token, max(1, $expiresIn - $this->settings->serviceTokenMargin));

            return $token;
        });
    }

    public function forget(array $scopes = [], ?string $api = null): void
    {
        $this->cache->forget($this->key($this->normalize($scopes), $api));
    }

    /**
     * @param  list<string>  $scopes
     * @return array{0: string, 1: int} token and lifetime in seconds
     */
    private function request(array $scopes, ?string $api): array
    {
        try {
            $response = $this->http
                ->timeout($this->settings->timeout)
                ->acceptJson()
                ->asForm()
                ->withHeaders($this->trace?->headers() ?? [])
                ->post($this->settings->endpoint('token'), array_filter([
                    'grant_type' => 'client_credentials',
                    'client_id' => $this->settings->clientId,
                    'client_secret' => $this->settings->clientSecret,
                    'scope' => implode(' ', $scopes),
                    'api' => $api,
                ], fn (?string $value) => $value !== null && $value !== ''));
        } catch (ConnectionException $e) {
            throw ServiceTokenException::unreachable($e);
        }

        $token = $response->json('access_token');

        if (! $response->successful() || ! is_string($token) || $token === '') {
            // league/oauth2-server puts the useful part (e.g. which scope) in "hint".
            $description = $response->json('error_description') ?? $response->json('message');
            $hint = $response->json('hint');

            if (is_string($hint) && $hint !== '') {
                $description = $description ? rtrim($description, '.').". {$hint}." : "{$hint}.";
            }

            throw ServiceTokenException::rejected($response->status(), $response->json('error'), $description);
        }

        return [$token, (int) ($response->json('expires_in') ?? 3600)];
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
