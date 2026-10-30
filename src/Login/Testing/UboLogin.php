<?php

namespace UBO\Common\Login\Testing;

use LogicException;

/**
 * Logs a person in for a test, without IAM or the login flow, so an application can test its
 * routes behind "ubo.login", "ubo.role" and @can.
 *
 *     UboLogin::actingAs(id: '42', roles: ['coordinador'], scopes: ['geografia:leer']);
 *     $this->get('/informes')->assertOk();
 */
final class UboLogin
{
    /**
     * @param  list<string>  $roles
     * @param  list<string>  $scopes
     * @param  list<string>  $audiences  "aud" after the client id; the client id is added
     */
    public static function actingAs(string $id = '1', array $roles = [], array $scopes = [], array $audiences = [], string $clientId = 'login-client', int $ttl = 3600): void
    {
        // The session it writes to belongs to a test, never to a real person.
        if (app()->isProduction()) {
            throw new LogicException('UboLogin::actingAs() logs anyone in and cannot be used in production.');
        }

        $session = app('session.store');

        if (! $session->isStarted()) {
            $session->start();
        }

        // So the guard also works outside an HTTP call of the test, like $this->actingAs().
        if (! request()->hasSession()) {
            request()->setLaravelSession($session);
        }

        $session->put('ubo_login', [
            'id' => $id,
            'client_id' => $clientId,
            'roles' => array_values($roles),
            'scopes' => array_values($scopes),
            'audiences' => [$clientId, ...array_values($audiences)],
            'access_token' => 'test-access-token',
            'expires_at' => now()->getTimestamp() + $ttl,
        ]);
    }
}
