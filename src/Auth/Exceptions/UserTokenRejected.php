<?php

namespace UBO\Common\Auth\Exceptions;

use RuntimeException;

/**
 * API-Auth refused to give a person's token (400 invalid_grant for a used code, a wrong
 * verifier or a used refresh token, 401 invalid_client for a wrong secret, 429 for too many
 * requests...). The message never includes the code, the verifier, the refresh token or the
 * client secret.
 */
final class UserTokenRejected extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $error = null,
        public readonly ?int $status = null,
    ) {
        parent::__construct($message);
    }

    /**
     * @param  string  $what  what was rejected, for the message: "authorization code" or "refresh token"
     */
    public static function rejected(int $status, ?string $error, ?string $description, string $what = 'authorization code'): self
    {
        return new self(
            "API-Auth rejected the {$what} ({$status} ".($error ?? 'error').')'.($description ? ": {$description}" : '.'),
            $error,
            $status,
        );
    }
}
