<?php

namespace UBO\Common\Login\Infrastructure;

use Closure;
use Psr\Log\LoggerInterface;
use UBO\Common\Auth\AuthSettings;
use UBO\Common\Auth\Contracts\LocalTokenValidator;
use UBO\Common\Auth\Contracts\TokenValidator;
use UBO\Common\Auth\Contracts\UserTokenClient;
use UBO\Common\Auth\Exceptions\AuthServerUnavailable;
use UBO\Common\Auth\Exceptions\InvalidToken;
use UBO\Common\Auth\Exceptions\UserTokenRejected;
use UBO\Common\Login\LoginSettings;

/**
 * The tokens of a logged-in person, as they are now: the vault entry of a handle after the
 * limit of the session, the renewal of the access token with the refresh token and the
 * periodic check with IAM. A person whose session ended (or whose entry is gone) has none.
 *
 * It keeps nothing between calls: after a renewal the vault holds the new expiry, so the
 * calls that follow do nothing.
 *
 * @internal
 *
 * @phpstan-import-type Entry from TokenVault
 */
final class LoginTokens
{
    /**
     * @param  Closure(): TokenValidator  $validator  the validator of UBO_AUTH_STRATEGY, resolved only when needed
     */
    public function __construct(
        private readonly LoginSettings $settings,
        private readonly AuthSettings $auth,
        private readonly TokenVault $vault,
        private readonly UserTokenClient $client,
        private readonly LocalTokenValidator $local,
        private readonly Closure $validator,
        private readonly LoggerInterface $log,
    ) {}

    /**
     * @return Entry|null null when the session ended or the access token cannot be used right now
     */
    public function current(string $handle): ?array
    {
        if (($login = $this->vault->get($handle)) === null) {
            return null;
        }

        if ($this->settings->maxAge > 0 && $this->now() - $login['started_at'] >= $this->settings->maxAge) {
            return $this->end($handle);
        }

        if ($this->dueForRenewal($login) && ($login = $this->renew($handle, $login)) === null) {
            return null;
        }

        if ($this->now() >= $login['expires_at'] - $this->auth->leeway) {
            // Without a refresh token nothing will renew it; with one, IAM did not answer or
            // refused for now, and the next request tries again.
            return $login['refresh_token'] === null ? $this->end($handle) : null;
        }

        return $this->revalidated($handle, $login);
    }

    /**
     * @param  Entry  $login
     */
    private function dueForRenewal(array $login): bool
    {
        return $login['refresh_token'] !== null
            && $this->now() >= $login['expires_at'] - max($this->settings->refreshMargin, $this->auth->leeway);
    }

    /**
     * Renew once, however many requests arrive: the first holds the lock and the others, once
     * they get it, find the new tokens.
     *
     * @param  Entry  $login
     * @return Entry|null null when the session ended
     */
    private function renew(string $handle, array $login): ?array
    {
        return $this->vault->locked($handle, function () use ($handle, $login) {
            if (($fresh = $this->vault->get($handle)) === null) {
                return null;
            }

            // Another request renewed while this one waited.
            if ($fresh['refresh_token'] !== $login['refresh_token'] || ! $this->dueForRenewal($fresh)) {
                return $fresh;
            }

            try {
                $issued = $this->client->refresh($this->settings->clientId(), $this->settings->clientSecret(), (string) $fresh['refresh_token']);
            } catch (UserTokenRejected $e) {
                return $e->error === 'invalid_grant' ? $this->rotated($handle, $fresh) : $this->keep($fresh, $e);
            } catch (AuthServerUnavailable $e) {
                return $this->keep($fresh, $e);
            }

            return $this->store($handle, $fresh, $issued);
        });
    }

    /**
     * IAM says the refresh token was used. If the lock failed, another request may have just
     * renewed with it: then its tokens are the ones to use, and only otherwise the session is over.
     *
     * @param  Entry  $login
     * @return Entry|null
     */
    private function rotated(string $handle, array $login): ?array
    {
        $now = $this->vault->get($handle);

        if ($now !== null && $now['refresh_token'] !== $login['refresh_token']) {
            return $now;
        }

        return $this->end($handle);
    }

    /**
     * IAM did not renew (it is down, its circuit is open, it limited the calls...): the token
     * in force keeps working until it expires, and the entry stays for the next try.
     *
     * @param  Entry  $login
     * @return Entry
     */
    private function keep(array $login, UserTokenRejected|AuthServerUnavailable $e): array
    {
        if ($e instanceof UserTokenRejected) {
            $this->log->warning('UBO.Common could not renew the access token of a login: '.$e->getMessage());
        }

        return $login;
    }

    /**
     * Validate the renewed token as the login does and keep it, with the roles, scopes and
     * audiences it carries. From here the old refresh token is useless, so whatever is wrong
     * ends the session.
     *
     * @param  Entry  $login
     * @param  array{access_token: string, refresh_token: string|null, expires_in: int}  $issued
     * @return Entry|null
     */
    private function store(string $handle, array $login, array $issued): ?array
    {
        try {
            $token = $this->local->validate($issued['access_token']);
        } catch (InvalidToken|AuthServerUnavailable $e) {
            $this->log->warning('UBO.Common ended a login because the renewed access token could not be validated: '.$e->getMessage());

            return $this->end($handle);
        }

        if ($token->isMachineToken() || $token->userId !== $login['id'] || strcasecmp($token->clientId, $this->settings->clientId()) !== 0) {
            $this->log->warning('UBO.Common ended a login because the renewed access token is of another person or client.');

            return $this->end($handle);
        }

        // The person lost all their roles in the application: IAM shows its "no access" page.
        if ($token->roles === []) {
            return $this->end($handle);
        }

        $renewed = [
            'roles' => $token->roles,
            'scopes' => $token->scopes,
            'audiences' => $token->audiences,
            'access_token' => $issued['access_token'],
            'refresh_token' => $issued['refresh_token'],
            'expires_at' => $token->expiresAt->getTimestamp(),
            'checked_at' => $this->now(),
        ] + $login;

        try {
            $this->vault->put($handle, $renewed);
        } catch (\Exception $e) {
            // The refresh token is spent and the new one is lost: the person goes through IAM again.
            $this->log->warning('UBO.Common could not save the renewed tokens of a login: '.$e->getMessage());

            return null;
        }

        return $renewed;
    }

    /**
     * Ask IAM, by the configured strategy, whether the token is still active, when it is time.
     * It is an extra control and not the authentication: if IAM does not answer, the login
     * goes on and it is asked again on the next request.
     *
     * @param  Entry  $login
     * @return Entry|null
     */
    private function revalidated(string $handle, array $login): ?array
    {
        $now = $this->now();

        if ($this->settings->revalidate === 0
            || $login['simulated']
            || ! in_array($this->auth->strategy, ['introspection', 'hybrid'], true)
            || ! $this->auth->hasClientCredentials()
            || $now - $login['checked_at'] < $this->settings->revalidate) {
            return $login;
        }

        try {
            ($this->validator)()->validate($login['access_token']);
        } catch (InvalidToken) {
            // Renewing revokes the token this request holds: if another request renewed
            // meanwhile, that is why it is inactive, and the person is still logged in.
            $current = $this->vault->get($handle);

            return $current !== null && $current['access_token'] !== $login['access_token'] ? $current : $this->end($handle);
        } catch (AuthServerUnavailable) {
            return $login;
        }

        $this->vault->update($handle, fn (array $entry) => ['checked_at' => $now] + $entry);

        return ['checked_at' => $now] + $login;
    }

    private function end(string $handle): null
    {
        $this->vault->forget($handle);

        return null;
    }

    private function now(): int
    {
        return now()->getTimestamp();
    }
}
