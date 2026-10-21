<?php

namespace UBO\Common\Auth\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use UBO\Common\Auth\AuthSettings;
use UBO\Common\Auth\Contracts\TokenValidator;
use UBO\Common\Auth\Domain\VerifiedToken;
use UBO\Common\Auth\Exceptions\InsufficientScope;
use UBO\Common\Auth\Exceptions\InvalidToken;

/**
 * Accepts only requests carrying a valid API-Auth token with every required scope.
 *
 *     Route::middleware('ubo.auth')                               // any valid token
 *     Route::middleware('ubo.auth:geografia:leer')                // one scope
 *     Route::middleware('ubo.auth:geografia:leer,sistemas:leer')  // all of them
 *
 * On success the token is available through VerifiedToken::from($request).
 */
final class Authenticate
{
    public function __construct(
        private readonly TokenValidator $validator,
        private readonly AuthSettings $settings,
    ) {}

    public function handle(Request $request, Closure $next, string ...$scopes): Response
    {
        $bearer = $request->bearerToken();

        if (! is_string($bearer) || $bearer === '') {
            throw InvalidToken::missing();
        }

        $token = $this->validator->validate($bearer);

        if (! $this->settings->allowUserTokens && ! $token->isMachineToken()) {
            throw InvalidToken::userTokenNotAllowed();
        }

        if ($missing = $token->missingScopes($scopes)) {
            throw new InsufficientScope($missing);
        }

        $request->attributes->set(VerifiedToken::REQUEST_ATTRIBUTE, $token);

        return $next($request);
    }
}
