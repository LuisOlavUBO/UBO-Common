<?php

namespace UBO\Common\ApiClient;

use Closure;
use Illuminate\Http\Client\Factory as HttpFactory;
use UBO\Common\Auth\Contracts\ServiceTokenProvider;

/**
 * Asynchronous call to a UBO API: each verb starts the request at once and returns a
 * UboPromise. Several promises run concurrently; wait() returns the Response.
 *
 *     $promise = UboApi::callAsync('api-maestros', $url)->get('/api/v1/geografia/paises');
 *     // ... other work ...
 *     $paises = $promise->wait()->json('data');
 *
 * @method UboPromise get(string $path, array|string|null $query = null)
 * @method UboPromise head(string $path, array|string|null $query = null)
 * @method UboPromise post(string $path, array $data = [])
 * @method UboPromise put(string $path, array $data = [])
 * @method UboPromise patch(string $path, array $data = [])
 * @method UboPromise delete(string $path, array $data = [])
 */
final class UboAsyncClient extends PendingUboRequest
{
    /** @var callable Guzzle handler shared by every async request, like Laravel's Http::pool(). */
    private $handler;

    public function __construct(ApiDefinition $api, HttpFactory $http, ServiceTokenProvider $tokens, callable $handler)
    {
        parent::__construct($api, $http, $tokens);

        $this->handler = $handler;
    }

    protected function run(Closure $send): UboPromise
    {
        // A shared handler (one curl multi handle) lets waiting on one promise advance all of them.
        return new UboPromise($send($this->prepare()->setHandler($this->handler)->async()), $this->api->name);
    }
}
