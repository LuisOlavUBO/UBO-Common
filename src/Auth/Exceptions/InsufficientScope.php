<?php

namespace UBO\Common\Auth\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;

/**
 * The token is valid but lacks scopes the route requires (403).
 */
final class InsufficientScope extends AuthorizationException
{
    /**
     * @param  list<string>  $missing
     */
    public function __construct(private readonly array $missing)
    {
        parent::__construct('Invalid scope(s) provided.');
    }

    /**
     * Scopes the token lacks (same method name as Passport's MissingScopeException).
     *
     * @return list<string>
     */
    public function scopes(): array
    {
        return $this->missing;
    }
}
