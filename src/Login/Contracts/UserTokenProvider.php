<?php

namespace UBO\Common\Login\Contracts;

use Illuminate\Auth\AuthenticationException;

/**
 * The access token of the person logged in for the current request, to call other APIs on
 * their behalf (UboApi ->asUser()). It is the token the login keeps renewed in its vault.
 */
interface UserTokenProvider
{
    /**
     * @throws AuthenticationException when no person is logged in (no session, a session that
     *                                 ended, a queued job or a command)
     */
    public function token(): string;

    /**
     * An API rejected $rejected: the token in force now if it is another one, renewing it
     * with the refresh token when it is still $rejected. Null when there is no other token
     * (nothing to renew with, IAM did not renew, or the session ended).
     */
    public function renewedAfter(string $rejected): ?string;
}
