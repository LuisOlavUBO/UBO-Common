<?php

namespace UBO\Common\Auth\Exceptions;

use Illuminate\Auth\AuthenticationException;

/**
 * The request has no usable token (401). Extends Laravel's AuthenticationException so each
 * application renders it with its own error format. $reason is for logs, not for clients.
 */
final class InvalidToken extends AuthenticationException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('Unauthenticated.');
    }

    public static function missing(): self
    {
        return new self('missing_token');
    }

    public static function malformed(): self
    {
        return new self('malformed_token');
    }

    public static function badSignature(): self
    {
        return new self('invalid_signature');
    }

    public static function expired(): self
    {
        return new self('expired_or_not_yet_valid');
    }

    public static function inactive(): self
    {
        return new self('inactive_token');
    }

    public static function userTokenNotAllowed(): self
    {
        return new self('user_token_not_allowed');
    }

    public static function wrongAudience(): self
    {
        return new self('wrong_audience');
    }
}
