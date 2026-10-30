<?php

namespace UBO\Common\Auth\Exceptions;

use RuntimeException;

/**
 * API-Auth refused to give a person's token (400 invalid_grant for a used code or a wrong
 * verifier, 401 invalid_client for a wrong secret...). The message never includes the code,
 * the verifier or the client secret.
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

    public static function rejected(int $status, ?string $error, ?string $description): self
    {
        return new self(
            "API-Auth rejected the authorization code ({$status} ".($error ?? 'error').')'.($description ? ": {$description}" : '.'),
            $error,
            $status,
        );
    }
}
