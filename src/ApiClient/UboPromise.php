<?php

namespace UBO\Common\ApiClient;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Promises\LazyPromise;
use Illuminate\Http\Client\Response;
use UBO\Common\ApiClient\Exceptions\UboApiException;

/**
 * A request in flight (the equivalent of a C# Task). The request is started when the
 * promise is created; wait() blocks until it finishes (the equivalent of await).
 */
final class UboPromise
{
    public function __construct(
        private readonly PromiseInterface $promise,
        private readonly string $api,
    ) {
        // Laravel builds async requests lazily; start it now so several promises run concurrently.
        if ($promise instanceof LazyPromise && $promise->promiseNeedsBuilt()) {
            $promise->buildPromise();
        }
    }

    /**
     * @throws UboApiException
     */
    public function wait(): Response
    {
        return ResultGuard::verify($this->api, $this->promise->wait());
    }

    /** The underlying Guzzle promise, for advanced composition. */
    public function promise(): PromiseInterface
    {
        return $this->promise;
    }
}
