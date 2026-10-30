<?php

namespace UBO\Common\Login\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * The login could not be completed: the callback does not match a login this browser started
 * (400), the token IAM gave is not usable (400) or IAM refused the person (403). $error is
 * the code for logs, not for people.
 */
final class LoginFailed extends HttpException
{
    public function __construct(int $status, string $message, public readonly ?string $error = null, ?Throwable $previous = null)
    {
        parent::__construct($status, $message, $previous);
    }

    public static function invalidState(string $reason): self
    {
        return new self(400, 'The login does not match one started from this browser: '.$reason.'.', 'invalid_state');
    }

    public static function denied(string $error): self
    {
        return new self(403, "IAM did not authorize the login ({$error}).", $error);
    }

    public static function rejected(?string $error, Throwable $previous): self
    {
        return new self(400, 'IAM rejected the authorization code'.($error ? " ({$error})" : '').'.', $error ?? 'rejected', $previous);
    }

    public static function invalidToken(string $reason, ?Throwable $previous = null): self
    {
        return new self(400, 'IAM gave a token this application cannot use: '.$reason.'.', 'invalid_token', $previous);
    }
}
