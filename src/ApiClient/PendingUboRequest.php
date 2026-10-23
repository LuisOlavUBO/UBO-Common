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
use UBO\Common\Tracing\Contracts\TraceContext;

/**
 * A request to one UBO API, preconfigured with its base URL, timeouts, headers, an
 * API-Auth token and the current trace (when the tracing module is enabled). Any configuration method of Laravel's HTTP client can be chained
 * (withHeaders, withQueryParameters, asForm, attach, timeout...).
 *
 * @mixin PendingRequest
 */
abstract class PendingUboRequest
{
    private const IDEMPOTENT_METHODS = ['GET', 'HEAD', 'PUT', 'DELETE', 'OPTIONS'];

    protected PendingRequest $request;

    public function __construct(
        protected readonly ApiDefinition $api,
        HttpFactory $http,
        private readonly ServiceTokenProvider $tokens,
        ?TraceContext $trace = null,
    ) {
        $this->request = $http->baseUrl($api->url)
            ->acceptJson()
            ->timeout($api->timeout)
            ->connectTimeout($api->connectTimeout)
            ->withHeaders($api->headers)
            ->withHeaders($trace?->headers() ?? []);
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
     * A copy of the configured request with a token and the retry policy:
     * - 401: renew the token once and retry immediately.
     * - connection error, or 5xx on an idempotent method: retry up to $api->retries times.
     */
    protected function prepare(): PendingRequest
    {
        $request = clone $this->request;

        try {
            $request->withToken($this->tokens->token(api: $this->api->name));
        } catch (ServiceTokenException $e) {
            throw UboApiException::authentication($this->api->name, $e);
        }

        $tokenRenewed = false;
        $retriesLeft = $this->api->retries;

        $when = function (Throwable $e, PendingRequest $request, ?string $method) use (&$tokenRenewed, &$retriesLeft): bool {
            if ($this->isUnauthorized($e) && ! $tokenRenewed) {
                $tokenRenewed = true;
                $this->tokens->forget(api: $this->api->name);
                $request->withToken($this->tokens->token(api: $this->api->name));

                return true;
            }

            $transient = $e instanceof ConnectionException
                || ($e instanceof RequestException && $e->response->serverError()
                    && in_array(strtoupper((string) $method), self::IDEMPOTENT_METHODS, true));

            return $transient && $retriesLeft-- > 0;
        };

        $delay = fn (int $attempt, ?Throwable $e = null): int => $this->isUnauthorized($e) ? 0 : $this->api->retryDelay * $attempt;

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

    private function isUnauthorized(?Throwable $e): bool
    {
        return $e instanceof RequestException && $e->response->status() === 401;
    }
}
