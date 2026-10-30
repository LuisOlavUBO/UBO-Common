<?php

namespace UBO\Common\Auth\Exceptions;

use RuntimeException;
use Throwable;

/**
 * This application could not get its own token from API-Auth: missing or wrong
 * credentials, a scope it may not request, or API-Auth unreachable.
 */
final class ServiceTokenException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $error = null,
        public readonly ?int $status = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function notConfigured(): self
    {
        return new self('UBO_AUTH_URL, UBO_AUTH_CLIENT_ID and UBO_AUTH_CLIENT_SECRET must be configured.');
    }

    public static function unreachable(Throwable $previous): self
    {
        return new self('API-Auth could not be reached: '.$previous->getMessage(), previous: $previous);
    }

    public static function unavailable(Throwable $previous): self
    {
        return new self('API-Auth is not available: '.$previous->getMessage(), previous: $previous);
    }

    public static function rejected(int $status, ?string $error, ?string $description): self
    {
        return new self(
            "API-Auth rejected the token request ({$status} ".($error ?? 'error').')'.($description ? ": {$description}" : '.'),
            $error,
            $status,
        );
    }
}
