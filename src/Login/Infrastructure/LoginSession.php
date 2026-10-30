<?php

namespace UBO\Common\Login\Infrastructure;

use Illuminate\Contracts\Session\Session;
use LogicException;

/**
 * What the login keeps in the server-side session: the login being started (state, PKCE
 * verifier, where to go back) and, once it ends, the person and their access token. Everything
 * that reads or writes it goes through here.
 *
 * @internal
 */
final class LoginSession
{
    private const PENDING = 'ubo_login_pending';

    private const CURRENT = 'ubo_login';

    public function __construct(private readonly Session $session) {}

    /**
     * Refuse sessions that live in the browser: the token would travel in a cookie.
     *
     * @throws LogicException
     */
    public static function assertServerSide(string $driver): void
    {
        if ($driver === 'cookie') {
            throw new LogicException('The login keeps the access token in the session, and SESSION_DRIVER=cookie stores the session in the browser. Use database, redis, file or another server-side driver.');
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
     * @param  array{id: string, client_id: string, roles: list<string>, scopes: list<string>, audiences: list<string>, access_token: string, expires_at: int}  $login
     */
    public function start(array $login): void
    {
        $this->session->put(self::CURRENT, $login);
    }

    /**
     * @return array{id: string, client_id: string, roles: list<string>, scopes: list<string>, audiences: list<string>, access_token: string, expires_at: int}|null
     */
    public function current(): ?array
    {
        $login = $this->session->get(self::CURRENT);

        return is_array($login)
            && is_string($login['id'] ?? null)
            && is_string($login['client_id'] ?? null)
            && is_string($login['access_token'] ?? null)
            && is_int($login['expires_at'] ?? null)
            ? $login + ['roles' => [], 'scopes' => [], 'audiences' => []]
            : null;
    }

    public function forget(): void
    {
        $this->session->forget([self::CURRENT, self::PENDING]);
    }
}
