<?php

namespace UBO\Common\Login\Exceptions;

use LogicException;

/**
 * A value the login needs is not configured. Raised when a route is used, never when the
 * module registers, so a build without a .env still works.
 */
final class LoginNotConfigured extends LogicException
{
    public static function missing(string $variable): self
    {
        return new self("{$variable} must be configured to log in with IAM.");
    }
}
