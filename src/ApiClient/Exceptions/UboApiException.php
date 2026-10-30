<?php

namespace UBO\Common\ApiClient\Exceptions;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use RuntimeException;
use Throwable;

/**
 * A call to a UBO API failed: the API answered 4xx/5xx or could not be reached.
 * UBO APIs answer errors in RFC 9457 (problem+json) with a stable code in "error",
 * exposed here as $errorCode (e.g. "SCOPE_INSUFICIENTE"). It also tells when the call was not
 * sent because the API's circuit is open, and when it ran out of time (deadline).
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
        private readonly bool $circuitOpen = false,
        private readonly bool $deadlineExceeded = false,
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

    public static function circuitOpen(string $api): self
    {
        return new self("[{$api}] circuit open: the API failed repeatedly, calls are not sent until it recovers.", $api, circuitOpen: true);
    }

    /**
     * The call ran out of time before a retry could be made; $last is the failure of the
     * last attempt (its status, code and cause are kept).
     */
    public static function deadlineExceeded(self $last): self
    {
        return new self(
            "[{$last->api}] deadline exceeded: ".$last->getMessage(),
            $last->api,
            $last->status,
            $last->errorCode,
            $last->title,
            $last->detail,
            $last->response,
            $last,
            deadlineExceeded: true,
        );
    }

    /** True when the API could not be reached (no HTTP response). */
    public function isConnectionError(): bool
    {
        $previous = $this->getPrevious();

        return $previous instanceof ConnectionException
            || ($previous instanceof self && $previous->isConnectionError());
    }

    /** True when the call was not sent because the API's circuit is open. */
    public function isCircuitOpen(): bool
    {
        return $this->circuitOpen;
    }

    /** True when the call ran out of time (its deadline) before a retry could be made. */
    public function isDeadlineExceeded(): bool
    {
        return $this->deadlineExceeded;
    }
}
