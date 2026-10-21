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
     * Client credentials token, as API-Auth issues it (sub = client id).
     *
     * @param  list<string>  $scopes
     */
    public function forClient(string $clientId, array $scopes = [], int $ttl = 3600): string
    {
        return $this->issue($clientId, $clientId, $scopes, Date::now()->toDateTimeImmutable(), $ttl);
    }

    /**
     * Token issued to a user through a client (password grant).
     *
     * @param  list<string>  $scopes
     */
    public function forUser(string $userId, string $clientId, array $scopes = [], int $ttl = 3600): string
    {
        return $this->issue($clientId, $userId, $scopes, Date::now()->toDateTimeImmutable(), $ttl);
    }

    /**
     * @param  list<string>  $scopes
     */
    public function expired(string $clientId, array $scopes = []): string
    {
        return $this->issue($clientId, $clientId, $scopes, Date::now()->subHours(2)->toDateTimeImmutable(), 3600);
    }

    /**
     * Token signed with a key API-Auth does not own (forged).
     *
     * @param  list<string>  $scopes
     */
    public function forged(string $clientId, array $scopes = []): string
    {
        return $this->issue($clientId, $clientId, $scopes, Date::now()->toDateTimeImmutable(), 3600, 'unknown');
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

    public function publicKey(): string
    {
        return (string) file_get_contents(__DIR__.'/keys/test-public.key');
    }

    /**
     * @param  list<string>  $scopes
     */
    private function issue(string $clientId, string $subject, array $scopes, DateTimeImmutable $issuedAt, int $ttl, string $key = 'test'): string
    {
        return (new Builder(new JoseEncoder, ChainedFormatter::default()))
            ->permittedFor($clientId)
            ->identifiedBy(Str::random(80))
            ->issuedAt($issuedAt)
            ->canOnlyBeUsedAfter($issuedAt)
            ->expiresAt($issuedAt->modify("+{$ttl} seconds"))
            ->relatedTo($subject)
            ->withClaim('scopes', $scopes)
            ->getToken(new Sha256, InMemory::file(__DIR__."/keys/{$key}-private.key"))
            ->toString();
    }
}
