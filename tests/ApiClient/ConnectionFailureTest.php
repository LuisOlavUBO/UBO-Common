<?php

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ConnectTimeoutException;
use GuzzleHttp\Exception\NetworkException;
use GuzzleHttp\Exception\NetworkTimeoutException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Psr7\Request as PsrRequest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use UBO\Common\ApiClient\ConnectionFailure;
use UBO\Common\ApiClient\Exceptions\UboApiException;
use UBO\Common\ApiClient\UboApi;

/*
| Laravel's ConnectionException wrapping a Guzzle exception, as the HTTP client builds it.
*/
function wrapped(Throwable $guzzle): ConnectionException
{
    return new ConnectionException($guzzle->getMessage(), 0, $guzzle);
}

/*
| A Guzzle 7 ConnectException: it exposes the cURL errno and error in its handler context.
*/
function guzzle7ConnectException(?int $errno, string $error = ''): ConnectionException
{
    $context = $errno === null ? [] : ['errno' => $errno, 'error' => $error];

    return wrapped(new class("cURL error {$errno}: {$error}", new PsrRequest('POST', 'https://maestros.test'), $context) extends ConnectException
    {
        /** @param  array<string, mixed>  $context */
        public function __construct(string $message, RequestInterface $request, private readonly array $context)
        {
            parent::__construct($message, $request);
        }

        /** @return array<string, mixed> */
        public function getHandlerContext(): array
        {
            return $this->context;
        }
    });
}

test('Guzzle 7: the errno and error tell whether the connection was established', function (?int $errno, string $error, bool $neverConnected) {
    expect(ConnectionFailure::neverConnected(guzzle7ConnectException($errno, $error)))->toBe($neverConnected);
})->with([
    'could not resolve host (6)' => [6, 'Could not resolve host: maestros.test', true],
    'connection refused (7)' => [7, 'Failed to connect to maestros.test port 443: Connection refused', true],
    'TLS handshake failed (35)' => [35, 'OpenSSL SSL_connect: SSL_ERROR_SYSCALL', true],
    'connect timeout (28)' => [28, 'Connection timed out after 5001 milliseconds', true],
    'connect timeout, newer libcurl (28)' => [28, 'Failed to connect to maestros.test port 443 after 5001 ms: Timeout was reached', true],
    'resolve timeout (28)' => [28, 'Resolving timed out after 5000 milliseconds', true],
    'read timeout (28)' => [28, 'Operation timed out after 10001 milliseconds with 0 bytes received', false],
    'empty reply (52)' => [52, 'Empty reply from server', false],
    'no errno in the context' => [null, '', false],
]);

test('Guzzle 8: the exception class tells whether the connection was established', function (Closure $guzzle, bool $neverConnected) {
    $request = new PsrRequest('POST', 'https://maestros.test');

    expect(ConnectionFailure::neverConnected(wrapped($guzzle($request))))->toBe($neverConnected);
})->with([
    'connection refused' => [fn ($r) => new ConnectException('cURL error 7: Connection refused', $r), true],
    'connect timeout' => [fn ($r) => new ConnectTimeoutException('cURL error 28: Connection timed out', $r), true],
    'read timeout' => [fn ($r) => new NetworkTimeoutException('cURL error 28: Operation timed out', $r), false],
    'receive failure' => [fn ($r) => new NetworkException('cURL error 56: Recv failure', $r), false],
])->skip(! class_exists(ConnectTimeoutException::class), 'Guzzle 8 only');

test('a Guzzle exception that is not a ConnectException may have reached the API', function () {
    $request = new PsrRequest('POST', 'https://maestros.test');

    expect(ConnectionFailure::neverConnected(wrapped(new RequestException('cURL error 56: Recv failure', $request))))->toBeFalse()
        ->and(ConnectionFailure::neverConnected(new ConnectionException('no previous')))->toBeFalse();
});

test('a simulated connection failure keeps the Guzzle exception as its origin', function () {
    config(['ubo-apis.defaults.retries' => 0]);
    Http::fake([...fakeTokenEndpoint(), 'https://maestros.test/*' => connectionRefused()]);

    try {
        UboApi::call('api-maestros', 'https://maestros.test')->get('/api/maestros/v1/geografia/paises');
        $this->fail('Expected UboApiException');
    } catch (UboApiException $e) {
        expect($e->isConnectionError())->toBeTrue()
            ->and($e->getPrevious()?->getPrevious())->toBeInstanceOf(ConnectException::class)
            ->and($e->getMessage())->toContain('cURL error 7');
    }
});

test('a simulated read timeout is reported as a timeout of the transfer', function () {
    config(['ubo-apis.defaults.retries' => 0]);
    Http::fake([...fakeTokenEndpoint(), 'https://maestros.test/*' => readTimeout()]);

    try {
        UboApi::call('api-maestros', 'https://maestros.test')->get('/api/maestros/v1/geografia/paises');
        $this->fail('Expected UboApiException');
    } catch (UboApiException $e) {
        $guzzle = $e->getPrevious()?->getPrevious();

        expect($e->isConnectionError())->toBeTrue()
            ->and($guzzle)->toBeInstanceOf(TransferException::class)
            ->and($e->getMessage())->toContain('Operation timed out');
    }
});
