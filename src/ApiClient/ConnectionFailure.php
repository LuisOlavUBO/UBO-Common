<?php

namespace UBO\Common\ApiClient;

use GuzzleHttp\Exception\ConnectException;
use Illuminate\Http\Client\ConnectionException;

/**
 * Tells whether a failed request never reached the API, so it can be sent again even when
 * its method is not idempotent.
 *
 * Guzzle 8 throws ConnectException (and ConnectTimeoutException) only before the connection
 * is established. Guzzle 7 also throws it for transfer timeouts and empty replies, so there
 * the cURL errno and error in its handler context decide. Anything else is treated as
 * "may have reached the API".
 *
 * @internal
 */
final class ConnectionFailure
{
    /** cURL errors raised before any byte of the request is sent: resolve host, connect, TLS handshake. */
    private const NOT_CONNECTED_ERRNOS = [6, 7, 35];

    /** CURLE_OPERATION_TIMEDOUT, used for both connect and transfer timeouts. */
    private const TIMEOUT_ERRNO = 28;

    /** cURL messages of a timeout while resolving the host or connecting (same as Guzzle 8's). */
    private const CONNECT_TIMEOUT_ERRORS = [
        'connection timed out',
        'connection timeout',
        'connection time-out',
        'resolving timed out',
        'name lookup timed out',
        'failed to connect',
    ];

    public static function neverConnected(ConnectionException $e): bool
    {
        $guzzle = $e->getPrevious();

        if (! $guzzle instanceof ConnectException) {
            return false;
        }

        // Guzzle 8: the class already says the connection was never established.
        if (! method_exists($guzzle, 'getHandlerContext')) {
            return true;
        }

        $context = $guzzle->getHandlerContext();
        $errno = $context['errno'] ?? null;

        if (in_array($errno, self::NOT_CONNECTED_ERRNOS, true)) {
            return true;
        }

        if ($errno !== self::TIMEOUT_ERRNO) {
            return false;
        }

        $error = strtolower((string) ($context['error'] ?? ''));

        foreach (self::CONNECT_TIMEOUT_ERRORS as $connectTimeout) {
            if (str_contains($error, $connectTimeout)) {
                return true;
            }
        }

        return false;
    }
}
