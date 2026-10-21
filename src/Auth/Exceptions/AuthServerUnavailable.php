<?php

namespace UBO\Common\Auth\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * API-Auth could not be reached or answered with an error, so the token could not be
 * checked (503). Validation fails closed: the request is never let through.
 */
final class AuthServerUnavailable extends HttpException
{
    public function __construct(string $message = 'API-Auth is unavailable.', ?Throwable $previous = null)
    {
        parent::__construct(503, $message, $previous);
    }
}
