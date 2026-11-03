<?php

namespace UBO\Common\Login\Infrastructure;

use Illuminate\Contracts\Session\Session;
use LogicException;

/**
 * What the login keeps in the server-side session: the login being started (state, PKCE
 * verifier, where to go back) and, once it ends, the handle of the person's entry in the
 * TokenVault, where their tokens are. Everything that reads or writes it goes through here.
 *
 * @internal
 */
final class LoginSession
{
    private const PENDING = 'ubo_login_pending';

    private const CURRENT = 'ubo_login';

    public function __construct(
        private readonly Session $session,
        private readonly TokenVault $vault,
    ) {}

    /**
     * Refuse sessions that live in the browser: the login ties the session to the person's
     * tokens, and a session in a cookie is one the browser holds and could replay.
     *
     * @throws LogicException
     */
    public static function assertServerSide(string $driver): void
    {
        if ($driver === 'cookie') {
            throw new LogicException('The login tokens must not travel in the browser, and SESSION_DRIVER=cookie stores the session in a cookie. Use database, redis, file or another server-side driver.');
        }
    }

    public function begin(string $state, string $verifier, ?string $destination, int $expiresAt): void
    {
        $this->session->put(self::PENDING, [
            'state' => $state,
            'verifier' => $verifier,
            'destination' => $destination,
            'expires_at' => $expiresAt,
        ]);
    }

    /**
     * The started login, which cannot be used again.
     *
     * @return array{state: string, verifier: string, destination: ?string, expires_at: int}|null
     */
    public function pull(): ?array
    {
        $pending = $this->session->pull(self::PENDING);

        return is_array($pending)
            && is_string($pending['state'] ?? null)
            && is_string($pending['verifier'] ?? null)
            && is_int($pending['expires_at'] ?? null)
            ? $pending
            : null;
    }

    /**
     * Start the session of a person: their tokens go to the vault under a new handle, which is
     * what the session keeps. The vault entry of a login this session had before stops working.
     *
     * @param  array{id: string, uuid: string, client_id: string, roles: list<string>, scopes: list<string>, audiences: list<string>, access_token: string, refresh_token: string|null, expires_at: int}  $login
     * @param  bool  $simulated  a login made by a test: nothing to renew or to check with IAM
     */
    public function start(array $login, bool $simulated = false): void
    {
        $this->forgetVault();

        $now = now()->getTimestamp();

        $this->session->put(self::CURRENT, $this->vault->create($login + ['started_at' => $now, 'checked_at' => $now, 'simulated' => $simulated]));
    }

    /** The handle of the person's vault entry, which is all the session keeps of their login. */
    public static function handleIn(Session $session): ?string
    {
        $handle = $session->get(self::CURRENT);

        return is_string($handle) && $handle !== '' ? $handle : null;
    }

    /**
     * What the vault holds now for this session, as it is: not renewed, not checked.
     *
     * @return array{id: string, uuid: string|null, client_id: string, roles: list<string>, scopes: list<string>, audiences: list<string>, access_token: string, refresh_token: string|null, expires_at: int, started_at: int, checked_at: int, simulated: bool}|null
     */
    public function current(): ?array
    {
        $handle = self::handleIn($this->session);

        return $handle === null ? null : $this->vault->get($handle);
    }

    public function forget(): void
    {
        $this->forgetVault();

        $this->session->forget([self::CURRENT, self::PENDING]);
    }

    private function forgetVault(): void
    {
        if (($handle = self::handleIn($this->session)) !== null) {
            $this->vault->forget($handle);
        }
    }
}
