<?php

namespace UBO\Common\Login\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Lets through a person who has at least one of the roles ("ubo.role:a,b"), compared as
 * written. Without a current login it behaves like "ubo.login".
 *
 *     Route::middleware('ubo.role:coordinador,admin')->get('/admin', ...);
 */
final class RequireRole extends EnsureLoggedIn
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $this->user();

        foreach ($roles as $role) {
            if ($user->hasRole($role)) {
                return parent::handle($request, $next);
            }
        }

        throw new AccessDeniedHttpException('This person has none of the required roles.');
    }
}
