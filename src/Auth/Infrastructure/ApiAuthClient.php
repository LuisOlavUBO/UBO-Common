<?php

namespace UBO\Common\Auth\Infrastructure;

use Closure;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Psr\Log\LoggerInterface;
use UBO\Common\Auth\AuthSettings;
use UBO\Common\Support\CircuitBreaker;
use UBO\Common\Tracing\Contracts\TraceContext;

/**
 * The only way the module calls API-Auth: timeouts, "traceparent" and the circuit breaker
 * shared by every call (public key, introspection and service tokens).
 *
 * @internal
 */
final class ApiAuthClient
{
    public const CIRCUIT = 'api-auth';

    private readonly CircuitBreaker $circuit;

    private bool $useCircuit = true;

    public function __construct(
        private readonly AuthSettings $settings,
        private readonly HttpFactory $http,
        CacheFactory $cache,
        ?LoggerInterface $log = null,
        private readonly ?TraceContext $trace = null,
    ) {
        $this->circuit = new CircuitBreaker(
            self::CIRCUIT,
            $cache->store($settings->cacheStore),
            $settings->cachePrefix,
            $settings->circuitFailures,
            $settings->circuitCooldown,
            $log,
        );
    }

    /**
     * A copy that always calls API-Auth and leaves the circuit as it is (diagnostics).
     */
    public function withoutCircuit(): self
    {
        $client = clone $this;
        $client->useCircuit = false;

        return $client;
    }

    public function circuit(): CircuitBreaker
    {
        return $this->circuit;
    }

    /**
     * @param  Closure(PendingRequest): Response  $send
     *
     * @throws ApiAuthUnreachable circuit open, connection error or 5xx
     */
    public function send(Closure $send): Response
    {
        if ($this->useCircuit && ! $this->circuit->allowsRequest()) {
            throw ApiAuthUnreachable::circuitOpen();
        }

        $request = $this->http
            ->connectTimeout($this->settings->connectTimeout)
            ->timeout($this->settings->timeout)
            ->withHeaders($this->trace?->headers() ?? []);

        if ($this->useCircuit) {
            $request->withMiddleware($this->circuit->middleware());
        }

        try {
            $response = $send($request);
        } catch (ConnectionException $e) {
            throw ApiAuthUnreachable::connection($e);
        }

        if ($response->serverError()) {
            throw ApiAuthUnreachable::serverError($response);
        }

        return $response;
    }
}
