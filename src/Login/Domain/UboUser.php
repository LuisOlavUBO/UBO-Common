<?php

namespace UBO\Common\Login\Domain;

use DateTimeImmutable;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The person logged in through IAM, as the access token describes them. It holds no
 * token: it is safe to dump, log or serialize.
 *
 *     $user = Auth::guard('ubo')->user();
 *     $user->getAuthIdentifier();   // the person's uuid in IAM (Auth::id()): what to store
 *     $user->hasRole('coordinador');
 *     $user->hasScope('geografia:leer');
 */
final readonly class UboUser implements Authenticatable
{
    /**
     * @param  string  $id  "uuid" of the token: the person's public id in IAM, the one other systems store
     * @param  string  $sub  "sub" of the token: the internal id of IAM, which must not be stored
     * @param  string  $clientId  login client the token was issued to
     * @param  list<string>  $roles  roles the person has in this application
     * @param  list<string>  $scopes
     * @param  list<string>  $audiences  "aud" claim: the client id and the applications that own the scopes
     */
    public function __construct(
        public string $id,
        public string $clientId,
        public array $roles,
        public array $scopes,
        public array $audiences,
        public DateTimeImmutable $expiresAt,
        public string $sub = '',
    ) {}

    /** Has this role, compared as written (role names are case-sensitive). */
    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthIdentifier(): string
    {
        return $this->id;
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    /** The password lives in IAM only. */
    public function getAuthPassword(): string
    {
        return '';
    }

    public function getRememberToken(): ?string
    {
        return null;
    }

    public function setRememberToken($value): void {}

    public function getRememberTokenName(): string
    {
        return '';
    }
}
