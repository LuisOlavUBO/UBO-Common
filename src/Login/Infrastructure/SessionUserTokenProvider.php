<?php

namespace UBO\Common\Login\Infrastructure;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\Request;
use UBO\Common\Login\Contracts\UserTokenProvider;
use UBO\Common\Login\Http\Middleware\EnsureLoggedIn;

/**
 * The token of the person of the request: the "ubo" guard decides whether there is one
 * (it renews when due and does not ask IAM twice in a request), then the vault holds the
 * token as the guard left it.
 *
 * @internal
 */
final class SessionUserTokenProvider implements UserTokenProvider
{
    /**
     * @param  Closure(): Request  $request
     * @param  Closure(): LoginTokens  $tokens  resolved only to renew after a rejection
     */
    public function __construct(
        private readonly Closure $request,
        private readonly AuthFactory $auth,
        private readonly TokenVault $vault,
        private readonly Closure $tokens,
    ) {}

    public function token(): string
    {
        $handle = $this->handle();

        if (! $this->auth->guard(EnsureLoggedIn::GUARD)->check()) {
            throw $this->unauthenticated();
        }

        return $this->vault->get($handle)['access_token'] ?? throw $this->unauthenticated();
    }

    public function renewedAfter(string $rejected): ?string
    {
        $token = ($this->tokens)()->renewedAfter($this->handle(), $rejected)['access_token'] ?? null;

        return $token !== null && $token !== $rejected ? $token : null;
    }

    /**
     * The handle of the person's vault entry. A request without a session (a queued job, a
     * command) or without a login has none.
     */
    private function handle(): string
    {
        $request = ($this->request)();

        $handle = $request->hasSession() ? LoginSession::handleIn($request->session()) : null;

        return $handle ?? throw $this->unauthenticated();
    }

    /** Aimed at the login route, as "ubo.login" does: a web request is sent to IAM. */
    private function unauthenticated(): AuthenticationException
    {
        return new AuthenticationException('Unauthenticated.', [EnsureLoggedIn::GUARD], route('ubo.login'));
    }
}
