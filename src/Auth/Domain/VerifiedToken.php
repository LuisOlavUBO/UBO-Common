<?php

namespace UBO\Common\Auth\Domain;

use DateTimeImmutable;
use Illuminate\Http\Request;

/**
 * A token that passed validation. The "ubo.auth" middleware stores it in the request:
 *
 *     $token = VerifiedToken::from($request);
 *     $token->clientId;
 */
final readonly class VerifiedToken
{
    public const REQUEST_ATTRIBUTE = 'ubo.auth.token';

    /**
     * @param  string  $id  token identifier (jti)
     * @param  string  $clientId  OAuth client the token was issued to
     * @param  string|null  $userId  null for machine-to-machine tokens
     * @param  list<string>  $scopes
     */
    public function __construct(
        public string $id,
        public string $clientId,
        public ?string $userId,
        public array $scopes,
        public DateTimeImmutable $expiresAt,
    ) {}

    /** The token validated by the "ubo.auth" middleware for this request, if any. */
    public static function from(Request $request): ?self
    {
        $token = $request->attributes->get(self::REQUEST_ATTRIBUTE);

        return $token instanceof self ? $token : null;
    }

    /** Issued with the client credentials grant (no user behind it). */
    public function isMachineToken(): bool
    {
        return $this->userId === null;
    }

    public function hasScope(string $scope): bool
    {
        return in_array('*', $this->scopes, true) || in_array($scope, $this->scopes, true);
    }

    /**
     * @param  list<string>  $scopes
     * @return list<string> the scopes this token lacks
     */
    public function missingScopes(array $scopes): array
    {
        return array_values(array_filter($scopes, fn (string $scope) => ! $this->hasScope($scope)));
    }
}
