<?php

namespace UBO\Common\Login\Infrastructure;

use Closure;
use DateTimeImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Http\Request;
use UBO\Common\Login\Domain\UboUser;

/**
 * The guard "ubo": the person is whoever the login left in the session, for as long as the
 * session of the login lasts (LoginTokens renews, checks and ends it). It keeps no user of its
 * own between calls, so a new request or a new test never sees the previous one's.
 */
final class UboGuard implements Guard
{
    private ?UboUser $forced = null;

    /**
     * @param  Closure(): Request  $request
     * @param  Closure(): LoginTokens  $tokens  resolved only when a person has a login
     */
    public function __construct(
        private readonly Closure $request,
        private readonly Closure $tokens,
    ) {}

    public function user(): ?UboUser
    {
        if ($this->forced !== null) {
            return $this->forced;
        }

        $request = ($this->request)();

        if (! $request->hasSession()) {
            return null;
        }

        if (($handle = LoginSession::handleIn($request->session())) === null) {
            return null;
        }

        // A session that ended (or whose vault entry is gone) leaves only a handle that opens nothing.
        if (($login = ($this->tokens)()->current($handle)) === null) {
            return null;
        }

        return new UboUser(
            id: $login['id'],
            clientId: $login['client_id'],
            roles: $login['roles'],
            scopes: $login['scopes'],
            audiences: $login['audiences'],
            expiresAt: (new DateTimeImmutable)->setTimestamp($login['expires_at']),
        );
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function guest(): bool
    {
        return ! $this->check();
    }

    public function id(): ?string
    {
        return $this->user()?->getAuthIdentifier();
    }

    /** The password lives in IAM: there is nothing to validate here. */
    public function validate(array $credentials = []): bool
    {
        return false;
    }

    public function hasUser(): bool
    {
        return $this->check();
    }

    /** For $this->actingAs($user, 'ubo') in tests. */
    public function setUser(Authenticatable $user): static
    {
        $this->forced = $user instanceof UboUser ? $user : null;

        return $this;
    }
}
