<?php

namespace UBO\Common\ApiClient;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Throwable;
use UBO\Common\ApiClient\Exceptions\UboApiException;
use UBO\Common\Auth\Exceptions\ServiceTokenException;

/**
 * Turns whatever Laravel's HTTP client produced (a response, or the exception an async
 * request resolves to) into a successful Response or a UboApiException.
 *
 * @internal
 */
final class ResultGuard
{
    public static function verify(string $api, mixed $result): Response
    {
        return match (true) {
            $result instanceof Response && ! $result->failed() => $result,
            $result instanceof Response => throw UboApiException::fromResponse($api, $result),
            $result instanceof RequestException => throw UboApiException::fromResponse($api, $result->response, $result),
            $result instanceof ConnectionException => throw UboApiException::connection($api, $result),
            $result instanceof ServiceTokenException => throw UboApiException::authentication($api, $result),
            $result instanceof Throwable => throw $result,
            default => throw new UboApiException("[{$api}] unexpected HTTP client result.", $api),
        };
    }
}
