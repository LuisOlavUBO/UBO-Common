<?php

namespace UBO\Common\ApiClient;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Throwable;
use UBO\Common\ApiClient\Exceptions\UboApiException;
use UBO\Common\Auth\Contracts\ServiceTokenProvider;
use UBO\Common\Auth\Exceptions\ServiceTokenException;
use UBO\Common\Support\CircuitBreaker;
use UBO\Common\Tracing\Contracts\TraceContext;

/**
 * A request to one UBO API, preconfigured with its base URL, timeouts, headers, an
 * API-Auth token and the current trace (when the tracing module is enabled). Any configuration method of Laravel's HTTP client can be chained
 * (withHeaders, withQueryParameters, asForm, attach, timeout...), and deadline() sets the time
 * the whole call may take. While the API's circuit is open, calls fail without being sent.
 *
 * @mixin PendingRequest
 */
abstract class PendingUboRequest
{
    private const IDEMPOTENT_METHODS = ['GET', 'HEAD', 'PUT', 'DELETE', 'OPTIONS'];

    protected PendingRequest $request;

    private int $deadline;

    /** Set by deadline(): then it always wins, even over a longer timeout. */
    private bool $deadlineSetOnCall = false;

    public function __construct(
        protected readonly ApiDefinition $api,
        HttpFactory $http,
        private readonly ServiceTokenProvider $tokens,
        ?TraceContext $trace = null,
        private readonly ?CircuitBreaker $circuit = null,
    ) {
        $this->deadline = $api->deadline;

        $this->request = $http->baseUrl($api->url)
            ->acceptJson()
            ->timeout($api->timeout)
            ->connectTimeout($api->connectTimeout)
            ->withHeaders($api->headers)
            ->withHeaders($trace?->headers() ?? []);

        if ($circuit !== null) {
            // Records the outcome of every attempt, retries included.
            $this->request->withMiddleware($circuit->middleware());
        }
    }

    /**
     * Seconds the whole call may take, attempts and waits included (default: the API's
     * "deadline"). Set here, it also cuts a longer timeout of the first attempt.
     */
    public function deadline(int $seconds): static
    {
        $this->deadline = max(1, $seconds);
        $this->deadlineSetOnCall = true;

        return $this;
    }

    /**
     * @param  array<int, mixed>  $parameters
     */
    public function __call(string $method, array $parameters): mixed
    {
        $result = $this->request->{$method}(...$parameters);

        return $result === $this->request ? $this : $result;
    }

    /**
     * The time this call may take, started now. A timeout longer than the API's deadline makes
     * the deadline that long, so the first attempt always fits, unless deadline() was called.
     */
    protected function budget(): CallBudget
    {
        return new CallBudget($this->deadlineSetOnCall ? $this->deadline : max($this->deadline, $this->attemptTimeout()));
    }

    /**
     * @throws UboApiException when the API's circuit is open
     */
    protected function guardCircuit(): void
    {
        if ($this->circuit !== null && ! $this->circuit->allowsRequest()) {
            throw UboApiException::circuitOpen($this->api->name);
        }
    }

    /**
     * The exception for a failed call, marked as out of time when a retry no longer fit.
     */
    protected function failure(UboApiException $e, CallBudget $budget): UboApiException
    {
        return $budget->exceeded ? UboApiException::deadlineExceeded($e) : $e;
    }

    /**
     * A copy of the configured request with a token and the retry policy:
     * - 401: renew the token once and retry immediately.
     * - connection error on an idempotent method, or on any method when the connection was
     *   never established, or 5xx on an idempotent method: retry up to $api->retries times.
     *   POST and PATCH are not retried once the API may have received them (e.g. a read timeout).
     * - every retry waits $api->retryWait() and must fit in the budget, with at least one
     *   second left for its attempt, whose timeout is cut to what is left.
     */
    protected function prepare(CallBudget $budget): PendingRequest
    {
        $request = clone $this->request;

        try {
            $request->withToken($this->tokens->token(api: $this->api->name));
        } catch (ServiceTokenException $e) {
            throw UboApiException::authentication($this->api->name, $e);
        }

        $tokenRenewed = false;
        $retriesLeft = $this->api->retries;
        $attemptTimeout = $this->attemptTimeout();
        $wait = 0;

        // Only a deadline() shorter than the timeout cuts the first attempt.
        if ($this->deadlineSetOnCall && $this->deadline < $attemptTimeout) {
            $request->timeout($this->deadline);
        }

        // Whether the next attempt, after waiting $ms, still fits; if so its timeout is cut to what is left.
        $fits = function (PendingRequest $request, int $ms) use ($budget, $attemptTimeout): bool {
            $left = $budget->remaining() - $ms / 1000;

            if ($left < 1) {
                $budget->exceeded = true;

                return false;
            }

            $request->timeout(min($attemptTimeout, $left));

            return true;
        };

        $when = function (Throwable $e, PendingRequest $request, ?string $method) use (&$tokenRenewed, &$retriesLeft, &$wait, $fits): bool {
            if ($this->isUnauthorized($e) && ! $tokenRenewed) {
                $tokenRenewed = true;
                $wait = 0;

                if (! $fits($request, 0)) {
                    return false;
                }

                $this->tokens->forget(api: $this->api->name);
                $request->withToken($this->tokens->token(api: $this->api->name));

                return true;
            }

            $idempotent = in_array(strtoupper((string) $method), self::IDEMPOTENT_METHODS, true);

            $transient = ($e instanceof ConnectionException && ($idempotent || ConnectionFailure::neverConnected($e)))
                || ($e instanceof RequestException && $e->response->serverError() && $idempotent);

            if (! $transient || $retriesLeft <= 0) {
                return false;
            }

            $wait = $this->api->retryWait($this->api->retries - $retriesLeft + 1);

            if (! $fits($request, $wait)) {
                return false;
            }

            $retriesLeft--;

            return true;
        };

        // Laravel asks $when first, then this for the wait before the next attempt.
        $delay = function () use (&$wait): int {
            return $wait;
        };

        return $request->retry($this->api->retries + 2, $delay, $when, throw: false);
    }

    /**
     * @param  Closure(PendingRequest): mixed  $send
     */
    abstract protected function run(Closure $send): mixed;

    /**
     * @param  array<string, mixed>|string|null  $query
     */
    public function get(string $path, array|string|null $query = null): mixed
    {
        return $this->run(fn (PendingRequest $r) => $query === null ? $r->get($path) : $r->get($path, $query));
    }

    /**
     * @param  array<string, mixed>|string|null  $query
     */
    public function head(string $path, array|string|null $query = null): mixed
    {
        return $this->run(fn (PendingRequest $r) => $query === null ? $r->head($path) : $r->head($path, $query));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function post(string $path, array $data = []): mixed
    {
        return $this->run(fn (PendingRequest $r) => $r->post($path, $data));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function put(string $path, array $data = []): mixed
    {
        return $this->run(fn (PendingRequest $r) => $r->put($path, $data));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function patch(string $path, array $data = []): mixed
    {
        return $this->run(fn (PendingRequest $r) => $r->patch($path, $data));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function delete(string $path, array $data = []): mixed
    {
        return $this->run(fn (PendingRequest $r) => $r->delete($path, $data));
    }

    private function attemptTimeout(): int|float
    {
        return $this->request->getOptions()['timeout'] ?? $this->api->timeout;
    }

    private function isUnauthorized(?Throwable $e): bool
    {
        return $e instanceof RequestException && $e->response->status() === 401;
    }
}
