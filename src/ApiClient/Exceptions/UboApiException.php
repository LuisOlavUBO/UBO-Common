<?php

namespace UBO\Common\ApiClient\Exceptions;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use RuntimeException;
use Throwable;

/**
 * A call to a UBO API failed: the API answered 4xx/5xx or could not be reached.
 * UBO APIs answer errors in RFC 9457 (problem+json) with a stable code in "error",
 * exposed here as $errorCode (e.g. "SCOPE_INSUFICIENTE").
 */
final class UboApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $api,
        public readonly ?int $status = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $title = null,
        public readonly ?string $detail = null,
        public readonly ?Response $response = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function fromResponse(string $api, Response $response, ?Throwable $previous = null): self
    {
        $json = $response->json();
        $json = is_array($json) ? $json : [];

        $errorCode = is_string($json['error'] ?? null) ? $json['error'] : null;
        $title = is_string($json['title'] ?? null) ? $json['title'] : null;
        $detail = $json['detail'] ?? $json['message'] ?? $json['error_description'] ?? null;
        $detail = is_string($detail) ? $detail : null;

        $message = "[{$api}] HTTP {$response->status()}"
            .($errorCode ? " {$errorCode}" : '')
            .($detail ?? $title ? ': '.($detail ?? $title) : '');

        return new self($message, $api, $response->status(), $errorCode, $title, $detail, $response, $previous);
    }

    public static function connection(string $api, Throwable $previous): self
    {
        return new self("[{$api}] could not be reached: {$previous->getMessage()}", $api, previous: $previous);
    }

    public static function authentication(string $api, Throwable $previous): self
    {
        return new self("[{$api}] could not get a token from API-Auth: {$previous->getMessage()}", $api, previous: $previous);
    }

    /** True when the API could not be reached (no HTTP response). */
    public function isConnectionError(): bool
    {
        return $this->getPrevious() instanceof ConnectionException;
    }
}
