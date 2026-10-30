<?php

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ConnectTimeoutException;
use GuzzleHttp\Exception\NetworkTimeoutException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise\Create;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use UBO\Common\Tests\Login\LoginTestCase;
use UBO\Common\Tests\TestCase;

pest()->extend(TestCase::class)->in('Support', 'Auth', 'ApiClient', 'Tracing', 'Problems', 'Health', 'SqlServer');
pest()->extend(LoginTestCase::class)->in('Login');

/*
| The query of the address a login redirect sends the browser to (the login module).
*/
function authorizeParams(TestResponse $response): array
{
    parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $params);

    return $params;
}

/*
| Steps of a login with IAM (the login module): the browser starts it, IAM issues a token,
| the browser comes back. The helpers use the test's own session and TokenFactory.
*/

const IAM_TOKEN_URL = 'https://api-auth.test/api/auth/oauth/token';

/**
 * The browser starts a login: the state it will bring back and the verifier the session keeps.
 *
 * @return array{0: string, 1: string}
 */
function startedLogin(string $query = ''): array
{
    $params = authorizeParams(test()->get('/iam/login'.$query));

    return [$params['state'], session('ubo_login_pending.verifier')];
}

function iamIssues(string $accessToken, int $status = 200): void
{
    Http::fake([IAM_TOKEN_URL => Http::response(
        $status === 200 ? ['token_type' => 'Bearer', 'expires_in' => 3600, 'access_token' => $accessToken, 'refresh_token' => 'refresh-token'] : ['error' => 'invalid_grant', 'error_description' => 'The provided authorization grant is invalid.'],
        $status,
    )]);
}

function personToken(array $overrides = []): string
{
    $overrides += ['id' => '42', 'client' => 'login-client', 'scopes' => ['geografia:leer'], 'roles' => ['coordinador'], 'audiences' => ['api-maestros']];

    return test()->tokens->forUser($overrides['id'], $overrides['client'], $overrides['scopes'], roles: $overrides['roles'], audiences: $overrides['audiences']);
}

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
| A read timeout that, like a real one, takes the attempt's whole timeout (moves the clock).
| $timeouts, when given, collects the timeout of each attempt, rounded because a few real
| milliseconds pass during the test.
*/
function slowTimeout(?array &$timeouts = null): Closure
{
    return function (Request $request, array $options) use (&$timeouts) {
        if ($timeouts !== null) {
            $timeouts[] = round((float) $options['timeout']);
        }

        test()->travel((int) ceil($options['timeout']))->seconds();

        return readTimeout()($request);
    };
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
