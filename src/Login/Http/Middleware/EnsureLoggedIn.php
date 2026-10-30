<?php

namespace UBO\Common\Login\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use UBO\Common\Login\Domain\UboUser;

/**
 * Lets through only a person with a current login ("ubo.login"). Without one it raises
 * Laravel's AuthenticationException aimed at the login route: Laravel then redirects a web
 * request (remembering the page that was asked for) and answers 401 to a JSON one.
 *
 *     Route::middleware('ubo.login')->get('/informes', ...);
 */
class EnsureLoggedIn
{
    public const GUARD = 'ubo';

    public function __construct(private readonly AuthFactory $auth) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->user();

        // Gate, @can and auth() inside the route see this person, as with auth:ubo.
        $this->auth->shouldUse(self::GUARD);

        return $next($request);
    }

    /**
     * @throws AuthenticationException
     */
    protected function user(): UboUser
    {
        $user = $this->auth->guard(self::GUARD)->user();

        return $user instanceof UboUser
            ? $user
            : throw new AuthenticationException('Unauthenticated.', [self::GUARD], route('ubo.login'));
    }
}
