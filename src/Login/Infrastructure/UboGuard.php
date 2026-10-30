<?php

namespace UBO\Common\Login\Infrastructure;

use Closure;
use DateTimeImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Http\Request;
use UBO\Common\Auth\AuthSettings;
use UBO\Common\Login\Domain\UboUser;

/**
 * The guard "ubo": the person is whoever the login left in the session, for as long as their
 * access token lives. It keeps no user of its own between calls, so a new request or a new
 * test never sees the previous one's.
 */
final class UboGuard implements Guard
{
    private ?UboUser $forced = null;

    /**
     * @param  Closure(): Request  $request
     */
    public function __construct(
        private readonly Closure $request,
        private readonly AuthSettings $settings,
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

        $session = new LoginSession($request->session());

        if (($login = $session->current()) === null) {
            return null;
        }

        // The token is not renewed here: when it expires the person goes through IAM again.
        if (now()->getTimestamp() >= $login['expires_at'] - $this->settings->leeway) {
            $session->forget();

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
