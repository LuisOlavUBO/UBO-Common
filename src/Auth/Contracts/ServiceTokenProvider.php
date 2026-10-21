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
     * @param  list<string>  $scopes
     *
     * @throws ServiceTokenException
     */
    public function token(array $scopes = []): string;

    /**
     * Drop the cached token for these scopes (e.g. it was rejected with 401).
     *
     * @param  list<string>  $scopes
     */
    public function forget(array $scopes = []): void;
}
