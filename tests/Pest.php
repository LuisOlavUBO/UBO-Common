<?php

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ConnectTimeoutException;
use GuzzleHttp\Exception\NetworkTimeoutException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise\Create;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use UBO\Common\Tests\TestCase;

pest()->extend(TestCase::class)->in('Support', 'Auth', 'ApiClient', 'Tracing', 'Problems', 'Health', 'SqlServer');

/*
| A fake API-Auth token endpoint answering client credentials requests.
*/
function fakeTokenEndpoint(string $token = 'service-token', int $expiresIn = 3600): array
{
    return ['https://api-auth.test/api/auth/oauth/token' => Http::response([
        'token_type' => 'Bearer',
        'expires_in' => $expiresIn,
        'access_token' => $token,
    ])];
}

/*
| Connection failures as the installed Guzzle's curl handler reports them, for Http::fake().
| Guzzle 8 tells the phase by the exception class; Guzzle 7 only by the cURL errno and error
| in the handler context. Http::failedConnection() carries neither.
*/
function connectionRefused(): Closure
{
    return failedConnectionWith(7, 'Failed to connect to maestros.test port 443: Connection refused', ConnectException::class);
}

function connectTimeout(): Closure
{
    return failedConnectionWith(28, 'Connection timed out after 5001 milliseconds', ConnectTimeoutException::class);
}

function readTimeout(): Closure
{
    return failedConnectionWith(28, 'Operation timed out after 10001 milliseconds with 0 bytes received', NetworkTimeoutException::class);
}

/*
| A connection error that does not say in which phase it happened.
*/
function unclassifiedConnectionError(): Closure
{
    return fn (Request $request) => Create::rejectionFor(class_exists(ConnectTimeoutException::class)
        ? new RequestException('cURL error 56: Recv failure', $request->toPsrRequest())
        : new ConnectException('Connection failed', $request->toPsrRequest()));
}

/*
| Fails the first $failures calls with $failure, then answers $response.
*/
function failThen(Closure $failure, int $failures, array $body = [], int $status = 200): Closure
{
    $calls = 0;

    return function (Request $request) use ($failure, $failures, $body, $status, &$calls) {
        return ++$calls <= $failures ? $failure($request) : Http::response($body, $status);
    };
}

/**
 * @param  class-string  $guzzle8Class  exception Guzzle 8 throws for this failure
 */
function failedConnectionWith(int $errno, string $error, string $guzzle8Class): Closure
{
    return function (Request $request) use ($errno, $error, $guzzle8Class) {
        $message = "cURL error {$errno}: {$error}";

        return Create::rejectionFor(class_exists(ConnectTimeoutException::class)
            ? new $guzzle8Class($message, $request->toPsrRequest())
            : new ConnectException($message, $request->toPsrRequest(), null, ['errno' => $errno, 'error' => $error]));
    };
}
