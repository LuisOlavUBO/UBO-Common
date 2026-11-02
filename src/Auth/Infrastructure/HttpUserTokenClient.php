<?php

namespace UBO\Common\Auth\Infrastructure;

use Illuminate\Http\Client\Response;
use UBO\Common\Auth\AuthSettings;
use UBO\Common\Auth\Contracts\UserTokenClient;
use UBO\Common\Auth\Exceptions\AuthServerUnavailable;
use UBO\Common\Auth\Exceptions\UserTokenRejected;

/**
 * Authorization code exchange and renewal with the refresh token (POST /api/auth/oauth/token)
 * and revocation (POST /api/auth/v1/logout) of a person's token, through ApiAuthClient so they share its timeouts,
 * trace and circuit with every other call to API-Auth.
 */
final class HttpUserTokenClient implements UserTokenClient
{
    public function __construct(
        private readonly AuthSettings $settings,
        private readonly ApiAuthClient $apiAuth,
    ) {}

    public function exchangeCode(string $clientId, string $clientSecret, string $code, string $redirectUri, string $codeVerifier): array
    {
        return $this->requestTokens('authorization code', [
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'code_verifier' => $codeVerifier,
        ]);
    }

    public function refresh(string $clientId, string $clientSecret, string $refreshToken): array
    {
        return $this->requestTokens('refresh token', [
            'grant_type' => 'refresh_token',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $refreshToken,
        ]);
    }

    public function revoke(string $accessToken): void
    {
        $url = $this->settings->endpoint('logout') ?? throw $this->notConfigured();

        $response = $this->send(fn ($request) => $request
            ->acceptJson()
            ->withToken($accessToken)
            ->post($url));

        // 401: it was already revoked or expired, which is what the caller wants.
        if (! $response->successful() && $response->status() !== 401) {
            throw $this->rejected($response, 'revocation');
        }
    }

    /**
     * @param  array<string, string>  $form
     * @return array{access_token: string, refresh_token: string|null, expires_in: int}
     */
    private function requestTokens(string $what, array $form): array
    {
        $url = $this->settings->endpoint('token') ?? throw $this->notConfigured();

        $response = $this->send(fn ($request) => $request
            ->acceptJson()
            ->asForm()
            ->post($url, $form));

        $token = $response->json('access_token');

        if (! $response->successful() || ! is_string($token) || $token === '') {
            throw $this->rejected($response, $what);
        }

        $refresh = $response->json('refresh_token');

        return [
            'access_token' => $token,
            'refresh_token' => is_string($refresh) && $refresh !== '' ? $refresh : null,
            'expires_in' => (int) ($response->json('expires_in') ?? 3600),
        ];
    }

    private function send(\Closure $send): Response
    {
        try {
            return $this->apiAuth->send($send);
        } catch (ApiAuthUnreachable $e) {
            throw new AuthServerUnavailable('Could not reach API-Auth: '.$e->getMessage(), $e);
        }
    }

    private function notConfigured(): AuthServerUnavailable
    {
        return new AuthServerUnavailable('UBO_AUTH_URL is not configured.');
    }

    private function rejected(Response $response, string $what): UserTokenRejected
    {
        // league/oauth2-server puts the useful part in "hint".
        $description = $response->json('error_description') ?? $response->json('message');
        $hint = $response->json('hint');

        if (is_string($hint) && $hint !== '') {
            $description = $description ? rtrim($description, '.').". {$hint}." : "{$hint}.";
        }

        return UserTokenRejected::rejected($response->status(), $response->json('error'), $description, $what);
    }
}
