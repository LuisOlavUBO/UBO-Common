<?php

namespace UBO\Common\Auth\Contracts;

use UBO\Common\Auth\Exceptions\AuthServerUnavailable;
use UBO\Common\Auth\Exceptions\UserTokenRejected;

/**
 * Gets and revokes the tokens of a person (not of this application) in API-Auth. The OAuth
 * client is given on each call: it is the application's login client (authorization code
 * grant), not the machine-to-machine one of "ubo-auth".
 */
interface UserTokenClient
{
    /**
     * Exchange the code API-Auth gave the browser for the person's access token.
     *
     * @return array{access_token: string, expires_in: int}
     *
     * @throws UserTokenRejected API-Auth refused the exchange (code used, verifier wrong...)
     * @throws AuthServerUnavailable API-Auth did not answer or its circuit is open
     */
    public function exchangeCode(string $clientId, string $clientSecret, string $code, string $redirectUri, string $codeVerifier): array;

    /**
     * Revoke an access token and its refresh token. A token that is already revoked or
     * expired is not an error.
     *
     * @throws AuthServerUnavailable API-Auth did not answer or its circuit is open
     */
    public function revoke(string $accessToken): void;
}
