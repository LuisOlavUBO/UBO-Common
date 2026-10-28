<?php

namespace UBO\Common\Auth\Infrastructure\Validators;

use DateInterval;
use Illuminate\Contracts\Foundation\Application;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Exception as JwtException;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\Token\RegisteredClaims;
use Lcobucci\JWT\UnencryptedToken;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Constraint\StrictValidAt;
use Lcobucci\JWT\Validation\Validator;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use UBO\Common\Auth\AuthSettings;
use UBO\Common\Auth\Contracts\PublicKeyProvider;
use UBO\Common\Auth\Contracts\TokenValidator;
use UBO\Common\Auth\Domain\VerifiedToken;
use UBO\Common\Auth\Exceptions\AuthServerUnavailable;
use UBO\Common\Auth\Exceptions\InvalidToken;
use UBO\Common\Auth\Testing\TestKey;

/**
 * Validates API-Auth tokens locally: RS256 signature with API-Auth's public key and
 * exp/nbf/iat. No network calls (besides downloading the key once), so it cannot see
 * revocations made before the token expires.
 */
final class JwtValidator implements TokenValidator
{
    public function __construct(
        private readonly PublicKeyProvider $keys,
        private readonly AuthSettings $settings,
        private readonly ClockInterface $clock,
        private readonly Application $app,
        private readonly LoggerInterface $log,
    ) {}

    public function validate(string $token): VerifiedToken
    {
        $jwt = $this->parse($token);

        if (! $this->signedBy($jwt, $this->trusted($this->keys->get()))) {
            // API-Auth may have rotated its keys: retry once with a fresh copy.
            $fresh = $this->keys->refresh();

            if ($fresh === null || ! $this->signedBy($jwt, $this->trusted($fresh))) {
                throw InvalidToken::badSignature();
            }
        }

        $validAt = new StrictValidAt($this->clock, new DateInterval('PT'.max(0, $this->settings->leeway).'S'));

        if (! (new Validator)->validate($jwt, $validAt)) {
            throw InvalidToken::expired();
        }

        return $this->toVerifiedToken($jwt);
    }

    private function parse(string $token): UnencryptedToken
    {
        if ($token === '') {
            throw InvalidToken::missing();
        }

        try {
            $jwt = (new Parser(new JoseEncoder))->parse($token);
        } catch (JwtException) {
            throw InvalidToken::malformed();
        }

        if (! $jwt instanceof UnencryptedToken) {
            throw InvalidToken::malformed();
        }

        return $jwt;
    }

    /**
     * The test key ships with the package and is public: in production a token it validates
     * could be forged by anyone, so no token is accepted (fail closed, 503).
     */
    private function trusted(string $publicKey): string
    {
        if ($this->app->isProduction() && TestKey::isTestKey($publicKey)) {
            $this->log->critical('UBO.Common refuses every token: the API-Auth public key in use is the public test key of TokenFactory. Configure the real key of API-Auth (UBO_AUTH_PUBLIC_KEY or the key API-Auth serves).');

            throw new AuthServerUnavailable('The API-Auth public key in use is the test key.');
        }

        return $publicKey;
    }

    private function signedBy(UnencryptedToken $jwt, string $publicKey): bool
    {
        // SignedWith also rejects tokens whose "alg" header is not RS256.
        return (new Validator)->validate($jwt, new SignedWith(new Sha256, InMemory::plainText($publicKey)));
    }

    /**
     * Passport claims: jti, aud = [client id] (plus the API name for tokens issued for
     * one API), sub = user id (or the client id for client credentials tokens), scopes, exp.
     */
    private function toVerifiedToken(UnencryptedToken $jwt): VerifiedToken
    {
        $claims = $jwt->claims();
        $audiences = $claims->get(RegisteredClaims::AUDIENCE, []);
        $clientId = $audiences[0] ?? null;
        $id = $claims->get(RegisteredClaims::ID);
        $subject = $claims->get(RegisteredClaims::SUBJECT);
        $scopes = $claims->get('scopes', []);

        if (! is_string($clientId) || $clientId === '' || ! is_string($id) || ! is_array($scopes)) {
            throw InvalidToken::malformed();
        }

        return new VerifiedToken(
            id: $id,
            clientId: $clientId,
            userId: VerifiedToken::userIdFromSubject($subject, $clientId),
            scopes: array_values(array_filter($scopes, 'is_string')),
            expiresAt: $claims->get(RegisteredClaims::EXPIRATION_TIME),
            audiences: array_values(array_filter($audiences, 'is_string')),
        );
    }
}
