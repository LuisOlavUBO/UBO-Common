<?php

namespace UBO\Common\Auth\Testing;

use UBO\Common\Auth\Contracts\TokenValidator;
use UBO\Common\Auth\Domain\VerifiedToken;
use UBO\Common\Auth\Exceptions\InvalidToken;

/**
 * Stands in for API-Auth's introspection in tests: a token is active when it is
 * correctly signed by the test key and was not revoked through TokenFactory.
 */
final class FakeIntrospectionValidator implements TokenValidator
{
    public function __construct(
        private readonly TokenFactory $factory,
        private readonly TokenValidator $local,
    ) {}

    public function validate(string $token): VerifiedToken
    {
        if ($this->factory->isRevoked($token)) {
            throw InvalidToken::inactive();
        }

        return $this->local->validate($token);
    }
}
