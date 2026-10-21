<?php

namespace UBO\Common\Auth\Contracts;

use UBO\Common\Auth\Domain\VerifiedToken;
use UBO\Common\Auth\Exceptions\AuthServerUnavailable;
use UBO\Common\Auth\Exceptions\InvalidToken;

/**
 * Validates an access token issued by API-Auth.
 */
interface TokenValidator
{
    /**
     * @throws InvalidToken when the token is not active
     * @throws AuthServerUnavailable when API-Auth cannot be reached
     */
    public function validate(string $token): VerifiedToken;
}
