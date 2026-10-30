<?php

namespace UBO\Common\Login\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use UBO\Common\Auth\Contracts\LocalTokenValidator;
use UBO\Common\Auth\Contracts\UserTokenClient;
use UBO\Common\Auth\Domain\VerifiedToken;
use UBO\Common\Auth\Exceptions\AuthServerUnavailable;
use UBO\Common\Auth\Exceptions\InvalidToken;
use UBO\Common\Auth\Exceptions\UserTokenRejected;
use UBO\Common\Login\Exceptions\LoginFailed;
use UBO\Common\Login\Exceptions\NoAccess;
use UBO\Common\Login\Infrastructure\LoginSession;
use UBO\Common\Login\LoginSettings;

/**
 * The three routes of the login: start it (send the browser to IAM), finish it (the callback
 * IAM sends the browser back to) and log out.
 */
final class LoginController
{
    public function __construct(
        private readonly LoginSettings $settings,
        private readonly UserTokenClient $tokens,
        private readonly LocalTokenValidator $validator,
        private readonly LoggerInterface $log,
    ) {}

    public function start(Request $request): RedirectResponse
    {
        $clientId = $this->settings->clientId();
        $this->settings->clientSecret();
        LoginSession::assertServerSide((string) config('session.driver'));

        $state = Str::random(40);
        $verifier = $this->base64Url(random_bytes(32));

        (new LoginSession($request->session()))->begin(
            $state,
            $verifier,
            $this->destination($request),
            now()->getTimestamp() + $this->settings->stateTtl,
        );

        $query = array_filter([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $this->settings->redirectUri(),
            'state' => $state,
            'code_challenge' => $this->base64Url(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',
            'prompt' => $this->settings->prompt === 'login' || $request->query('reauth') === '1' ? 'login' : null,
        ]);

        return redirect()->away($this->settings->authorizeUrl().'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986));
    }

    public function callback(Request $request): RedirectResponse
    {
        $clientId = $this->settings->clientId();
        $clientSecret = $this->settings->clientSecret();
        LoginSession::assertServerSide((string) config('session.driver'));

        $session = new LoginSession($request->session());
        // Read once, whatever follows: a state is never valid twice.
        $pending = $session->pull();

        if (is_string($error = $request->query('error')) && $error !== '') {
            throw LoginFailed::denied($error);
        }

        $this->assertMatches($pending, $request);

        try {
            $issued = $this->tokens->exchangeCode($clientId, $clientSecret, (string) $request->query('code'), $this->settings->redirectUri(), $pending['verifier']);
        } catch (UserTokenRejected $e) {
            throw LoginFailed::rejected($e->error, $e);
        }

        $token = $this->verify($issued['access_token']);

        if ($token->roles === []) {
            throw new NoAccess;
        }

        // A session id from before the login, which someone else may know, must not carry the person.
        $request->session()->regenerate(destroy: true);
        $request->session()->regenerateToken();
        $request->session()->forget('url.intended');

        $session->start([
            'id' => (string) $token->userId,
            'client_id' => $token->clientId,
            'roles' => $token->roles,
            'scopes' => $token->scopes,
            'audiences' => $token->audiences,
            'access_token' => $issued['access_token'],
            'expires_at' => $token->expiresAt->getTimestamp(),
        ]);

        return redirect()->to($pending['destination'] ?? $this->settings->home);
    }

    public function logout(Request $request): RedirectResponse
    {
        $target = $request->input('scope') === 'local'
            ? $this->settings->home
            : $this->settings->logoutUrl().'?'.http_build_query([
                'client_id' => $this->settings->clientId(),
                'return' => $this->settings->logoutReturnUrl(),
            ], '', '&', PHP_QUERY_RFC3986);

        $login = (new LoginSession($request->session()))->current();

        if ($login !== null) {
            try {
                $this->tokens->revoke($login['access_token']);
            } catch (AuthServerUnavailable|UserTokenRejected $e) {
                // The session ends here anyway: the token expires on its own.
                $this->log->warning('UBO.Common could not revoke the access token at logout: '.$e->getMessage());
            }
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $request->input('scope') === 'local' ? redirect()->to($target) : redirect()->away($target);
    }

    /**
     * Where to go back to after the login: the page asked for, only when it is of this application.
     */
    private function destination(Request $request): ?string
    {
        foreach ([$request->query('redirect'), $request->session()->get('url.intended')] as $candidate) {
            if (is_string($candidate) && $candidate !== '' && $this->settings->isLocal($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  array{state: string, verifier: string, destination: ?string, expires_at: int}|null  $pending
     *
     * @phpstan-assert array{state: string, verifier: string, destination: ?string, expires_at: int} $pending
     */
    private function assertMatches(?array $pending, Request $request): void
    {
        $state = $request->query('state');
        $code = $request->query('code');

        match (true) {
            $pending === null => throw LoginFailed::invalidState('no login was started'),
            $pending['expires_at'] < now()->getTimestamp() => throw LoginFailed::invalidState('it took too long'),
            ! is_string($state) || ! hash_equals($pending['state'], $state) => throw LoginFailed::invalidState('the state does not match'),
            ! is_string($code) || $code === '' => throw LoginFailed::invalidState('the code is missing'),
            default => null,
        };
    }

    /**
     * The token comes straight from IAM in exchange for a code that works once, so only the
     * token itself is checked (signature and expiry), not whether it was revoked.
     */
    private function verify(string $accessToken): VerifiedToken
    {
        try {
            $token = $this->validator->validate($accessToken);
        } catch (InvalidToken $e) {
            throw LoginFailed::invalidToken($e->reason, $e);
        }

        if (strcasecmp($token->clientId, $this->settings->clientId()) !== 0) {
            throw LoginFailed::invalidToken('it was issued to another client');
        }

        if ($token->isMachineToken()) {
            throw LoginFailed::invalidToken('it was not issued to a person');
        }

        return $token;
    }

    private function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
