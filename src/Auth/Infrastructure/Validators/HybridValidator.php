<?php

namespace UBO\Common\Auth\Infrastructure\Validators;

use UBO\Common\Auth\Contracts\TokenValidator;
use UBO\Common\Auth\Domain\VerifiedToken;

/**
 * Local check first (forged, malformed or expired tokens never reach API-Auth),
 * then a cached introspection so revoked tokens are rejected.
 */
final class HybridValidator implements TokenValidator
{
    public function __construct(
        private readonly TokenValidator $local,
        private readonly TokenValidator $remote,
    ) {}

    public function validate(string $token): VerifiedToken
    {
        $verified = $this->local->validate($token);

        $this->remote->validate($token);

        return $verified;
    }
}
