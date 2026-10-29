<?php

namespace UBO\Common\Auth\Infrastructure;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * API-Auth did not answer usefully: its circuit is open, it could not be reached, or it
 * answered 5xx. Each caller turns it into its public exception.
 *
 * @internal
 */
final class ApiAuthUnreachable extends RuntimeException
{
    private function __construct(
        string $message,
        public readonly ?Response $response = null,
        ?ConnectionException $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function circuitOpen(): self
    {
        return new self('API-Auth circuit open: it failed repeatedly, calls are not sent until it recovers.');
    }

    public static function connection(ConnectionException $e): self
    {
        return new self('API-Auth could not be reached: '.$e->getMessage(), previous: $e);
    }

    public static function serverError(Response $response): self
    {
        return new self("API-Auth answered {$response->status()}.", $response);
    }

    public function isCircuitOpen(): bool
    {
        return $this->response === null && $this->getPrevious() === null;
    }
}
