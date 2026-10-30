<?php

namespace UBO\Common\Auth\Testing;

use DateTimeImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Builder;
use LogicException;
use UBO\Common\Auth\AuthServiceProvider;
use UBO\Common\Auth\Infrastructure\Validators\JwtValidator;

/**
 * Issues API-Auth-like tokens in tests, so applications can test routes protected by
 * "ubo.auth" without API-Auth running. Tokens go through the real validators.
 *
 *     $tokens = TokenFactory::fake();
 *     $this->withToken($tokens->forClient('client-id', ['geografia:leer']))->getJson(...);
 */
final class TokenFactory
{
    /** @var array<string, true> */
    private array $revoked = [];

    /**
     * Trust the test key and replace introspection with this factory's revocation list.
     */
    public static function fake(): self
    {
        // Production would then trust a key anyone with the package can sign with.
        if (app()->isProduction()) {
            throw new LogicException('TokenFactory::fake() trusts the public test key and cannot be used in production.');
        }

        $factory = new self;

        config([
            'ubo-auth.public_key' => $factory->publicKey(),
            'ubo-auth.url' => config('ubo-auth.url') ?: 'https://api-auth.ubo.test',
        ]);

        app()->bind(AuthServiceProvider::INTROSPECTION, fn ($app) => new FakeIntrospectionValidator(
            $factory,
            $app->make(JwtValidator::class),
        ));

        return $factory;
    }

    /**
     * Client credentials token, as API-Auth issues it (sub = client id). It is issued for
     * $api (added to "aud"); by default the API under test (ubo-auth.api), and "" for none.
     *
     * @param  list<string>  $scopes
     * @param  list<string>  $roles  goes in the "roles" claim
     */
    public function forClient(string $clientId, array $scopes = [], int $ttl = 3600, ?string $api = null, array $roles = []): string
    {
        return $this->issue($clientId, $clientId, $scopes, Date::now()->toDateTimeImmutable(), $ttl, api: $api ?? $this->apiUnderTest(), roles: $roles);
    }

    /**
     * Token issued to a user through a client (password grant), or any token with a custom
     * "sub". Like forClient(), it is issued for $api (by default the API under test, "" for
     * none), so a "sub" that only differs in case from the client id, which is a machine
     * token, also passes the audience check.
     *
     * With $audiences the token is issued as API-Auth does for a user: "aud" is the client id
     * followed by those applications, and $api is not added.
     *
     * @param  list<string>  $scopes
     * @param  list<string>  $roles  goes in the "roles" claim
     * @param  list<string>|null  $audiences  applications named in "aud" after the client id
     */
    public function forUser(string $userId, string $clientId, array $scopes = [], int $ttl = 3600, ?string $api = null, array $roles = [], ?array $audiences = null): string
    {
        return $this->issue($clientId, $userId, $scopes, Date::now()->toDateTimeImmutable(), $ttl, api: $api ?? $this->apiUnderTest(), roles: $roles, applications: $audiences);
    }

    /**
     * @param  list<string>  $scopes
     */
    public function expired(string $clientId, array $scopes = []): string
    {
        return $this->issue($clientId, $clientId, $scopes, Date::now()->subHours(2)->toDateTimeImmutable(), 3600, api: $this->apiUnderTest());
    }

    /**
     * Token signed with a key API-Auth does not own (forged).
     *
     * @param  list<string>  $scopes
     */
    public function forged(string $clientId, array $scopes = []): string
    {
        return $this->issue($clientId, $clientId, $scopes, Date::now()->toDateTimeImmutable(), 3600, 'unknown', $this->apiUnderTest());
    }

    /** Mark a token as revoked in API-Auth (seen by the introspection and hybrid strategies). */
    public function revoke(string $token): void
    {
        $this->revoked[$token] = true;
    }

    public function isRevoked(string $token): bool
    {
        return isset($this->revoked[$token]);
    }

    private function apiUnderTest(): ?string
    {
        $api = config('ubo-auth.api');

        return is_string($api) && $api !== '' ? $api : null;
    }

    public function publicKey(): string
    {
        return (string) file_get_contents(__DIR__.'/keys/test-public.key');
    }

    /**
     * @param  list<string>  $scopes
     * @param  list<string>  $roles
     * @param  list<string>|null  $applications  named in "aud" after the client id instead of $api
     */
    private function issue(string $clientId, string $subject, array $scopes, DateTimeImmutable $issuedAt, int $ttl, string $key = 'test', ?string $api = null, array $roles = [], ?array $applications = null): string
    {
        return (new Builder(new JoseEncoder, ChainedFormatter::default()))
            ->permittedFor(...array_filter([$clientId, ...($applications ?? [$api])]))
            ->identifiedBy(Str::random(80))
            ->issuedAt($issuedAt)
            ->canOnlyBeUsedAfter($issuedAt)
            ->expiresAt($issuedAt->modify("+{$ttl} seconds"))
            ->relatedTo($subject)
            ->withClaim('scopes', $scopes)
            ->withClaim('roles', $roles)
            ->getToken(new Sha256, InMemory::file(__DIR__."/keys/{$key}-private.key"))
            ->toString();
    }
}
