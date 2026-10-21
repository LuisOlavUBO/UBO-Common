<?php

namespace UBO\Common\Auth\Contracts;

use UBO\Common\Auth\Exceptions\ServiceTokenException;

/**
 * Gets access tokens for THIS application from API-Auth (client credentials grant).
 * Tokens are cached and renewed before they expire.
 */
interface ServiceTokenProvider
{
    /**
     * With $api, the token is only valid for that API (its name in API-Auth, e.g.
     * "api-maestros") and, without $scopes, carries every scope this application's
     * roles grant there.
     *
     * @param  list<string>  $scopes
     *
     * @throws ServiceTokenException
     */
    public function token(array $scopes = [], ?string $api = null): string;

    /**
     * Drop the cached token for these scopes and API (e.g. it was rejected with 401).
     *
     * @param  list<string>  $scopes
     */
    public function forget(array $scopes = [], ?string $api = null): void;
}
