<?php

namespace UBO\Common\Auth\Infrastructure;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository;
use UBO\Common\Auth\AuthSettings;
use UBO\Common\Auth\Contracts\PublicKeyProvider;
use UBO\Common\Auth\Exceptions\AuthServerUnavailable;

/**
 * Downloads API-Auth's public key (GET /api/auth/v1/public-key) and caches it.
 */
final class HttpPublicKeyProvider implements PublicKeyProvider
{
    /** Minimum seconds between two forced downloads, so forged tokens cannot flood API-Auth. */
    private const REFRESH_COOLDOWN = 60;

    private readonly Repository $cache;

    public function __construct(
        private readonly AuthSettings $settings,
        private readonly ApiAuthClient $apiAuth,
        CacheFactory $cache,
    ) {
        $this->cache = $cache->store($settings->cacheStore);
    }

    public function get(): string
    {
        return $this->cache->remember($this->key(), $this->settings->publicKeyTtl, fn () => $this->download());
    }

    /**
     * The cached key is replaced only after the new one is downloaded: if API-Auth is
     * down, the key that already works is kept and the caller treats the token as
     * badly signed, instead of every request failing until API-Auth comes back.
     */
    public function refresh(): ?string
    {
        if (! $this->cache->add($this->key().':refreshed', true, self::REFRESH_COOLDOWN)) {
            return null;
        }

        try {
            $key = $this->download();
        } catch (AuthServerUnavailable) {
            return null;
        }

        $this->cache->put($this->key(), $key, $this->settings->publicKeyTtl);

        return $key;
    }

    private function download(): string
    {
        $url = $this->settings->endpoint('public_key')
            ?? throw new AuthServerUnavailable('UBO_AUTH_URL is not configured.');

        try {
            $response = $this->apiAuth->send(fn ($request) => $request->get($url));
        } catch (ApiAuthUnreachable $e) {
            throw new AuthServerUnavailable('Could not download the API-Auth public key: '.$e->getMessage(), $e);
        }

        if (! $response->successful() || ! str_contains($response->body(), 'BEGIN PUBLIC KEY')) {
            throw new AuthServerUnavailable("API-Auth public key endpoint answered {$response->status()}.");
        }

        return $response->body();
    }

    private function key(): string
    {
        return $this->settings->cachePrefix.'public-key';
    }
}
