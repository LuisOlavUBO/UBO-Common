<?php

namespace UBO\Common\Support;

use GuzzleHttp\Promise\Create;
use Illuminate\Contracts\Cache\Repository;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Stops calling a service that keeps failing. The state lives in the cache, so every worker
 * sharing the store sees the same circuit:
 *
 * - closed: requests go through; failures are counted, a success resets the count.
 * - open: after $failures failures in a row, no request goes through for $cooldown seconds.
 * - half-open: once the cooldown is over, a single process sends a probe; its result closes
 *   the circuit or opens it for another cooldown.
 *
 * The circuit is an optimisation, never a requirement: if the cache store fails, requests go
 * through as if it were closed.
 *
 * @internal
 */
final class CircuitBreaker
{
    public function __construct(
        private readonly string $name,
        private readonly Repository $cache,
        private readonly string $prefix,
        private readonly int $failures,
        private readonly int $cooldown,
        private readonly ?LoggerInterface $log = null,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function allowsRequest(): bool
    {
        try {
            if ($this->cache->has($this->key('open'))) {
                return false;
            }

            if ((int) $this->cache->get($this->key('failures'), 0) < $this->failures) {
                return true;
            }

            // Half-open: only the process that takes the probe lock goes through.
            return $this->cache->add($this->key('probe'), true, $this->cooldown);
        } catch (Throwable) {
            return true;
        }
    }

    public function isOpen(): bool
    {
        try {
            return $this->cache->has($this->key('open'));
        } catch (Throwable) {
            return false;
        }
    }

    public function recordSuccess(): void
    {
        try {
            $wasOpen = (int) $this->cache->get($this->key('failures'), 0) >= $this->failures;

            $this->cache->forget($this->key('failures'));
            $this->cache->forget($this->key('open'));
            $this->cache->forget($this->key('probe'));

            if ($wasOpen) {
                $this->cache->forget($this->key('logged'));
                $this->log?->info("Circuit [{$this->name}] closed: the service answers again.");
            }
        } catch (Throwable) {
            // A broken cache store never breaks the call.
        }
    }

    public function recordFailure(): void
    {
        try {
            // The count expires $cooldown seconds after the first failure, so isolated
            // failures far apart never add up.
            $this->cache->add($this->key('failures'), 0, $this->cooldown);
            $failures = (int) $this->cache->increment($this->key('failures'));

            if ($failures < $this->failures) {
                return;
            }

            $this->cache->put($this->key('open'), true, $this->cooldown);
            // Outlive the open state, so the circuit is half-open (not closed) when it ends.
            $this->cache->put($this->key('failures'), $failures, $this->cooldown * 2);
            $this->cache->forget($this->key('probe'));

            if ($this->cache->add($this->key('logged'), true, $this->cooldown * 2)) {
                $this->log?->warning("Circuit [{$this->name}] opened after {$failures} failures in a row: calls fail at once for {$this->cooldown} seconds.");
            }
        } catch (Throwable) {
            // A broken cache store never breaks the call.
        }
    }

    /**
     * Guzzle middleware recording the outcome of every attempt: a response below 500 is a
     * success (the service answered), a 5xx or a network error is a failure.
     */
    public function middleware(): callable
    {
        return fn (callable $handler) => function (RequestInterface $request, array $options) use ($handler) {
            try {
                $promise = $handler($request, $options);
            } catch (Throwable $e) {
                // Some handlers (and Http::fake() stubs) throw instead of rejecting.
                $this->recordFailure();

                throw $e;
            }

            return $promise->then(
                function (ResponseInterface $response) {
                    $response->getStatusCode() >= 500 ? $this->recordFailure() : $this->recordSuccess();

                    return $response;
                },
                function (mixed $reason) {
                    $this->recordFailure();

                    return Create::rejectionFor($reason);
                },
            );
        };
    }

    private function key(string $suffix): string
    {
        return "{$this->prefix}circuit:{$this->name}:{$suffix}";
    }
}
