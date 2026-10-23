<?php

namespace UBO\Common\ApiClient;

use GuzzleHttp\Utils;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use LogicException;
use UBO\Common\Auth\Contracts\ServiceTokenProvider;
use UBO\Common\Tracing\Contracts\TraceContext;

/**
 * Builds clients for any UBO API: the caller gives the API's name in API-Auth and its
 * URL. Used through the UboApi facade.
 */
final class ApiClientManager
{
    /** @var callable|null Guzzle handler shared by async requests so they run concurrently. */
    private $asyncHandler = null;

    /** @var array<string, string> API name => fake base URL, set by fake(). */
    private array $fakedApis = [];

    public function __construct(
        private readonly Config $config,
        private readonly HttpFactory $http,
        private readonly Container $container,
    ) {}

    /**
     * @param  string  $api  name of the API in API-Auth (e.g. "api-maestros"); the token is only valid there
     * @param  string|null  $url  base URL of the API, usually read from this application's config
     */
    public function call(string $api, ?string $url): UboClient
    {
        return new UboClient($this->definition($api, $url), $this->http, $this->tokens(), $this->trace());
    }

    public function callAsync(string $api, ?string $url): UboAsyncClient
    {
        $this->asyncHandler ??= Utils::chooseHandler();

        return new UboAsyncClient($this->definition($api, $url), $this->http, $this->tokens(), $this->asyncHandler, $this->trace());
    }

    /**
     * Wait for several promises (they already run concurrently) and return their
     * responses with the same keys. Throws the first UboApiException found.
     *
     * @param  array<array-key, UboPromise>  $promises
     * @return array<array-key, Response>
     */
    public function all(array $promises): array
    {
        return array_map(fn (UboPromise $promise) => $promise->wait(), $promises);
    }

    public function definition(string $api, ?string $url): ApiDefinition
    {
        return ApiDefinition::fromConfig($api, $this->fakedApis[$api] ?? $url, (array) $this->config->get('ubo-apis', []));
    }

    /**
     * Fake API-Auth and the UBO APIs in tests. Keys start with the API name:
     *
     *     UboApi::fake(['api-maestros/api/v1/geografia/*' => ['data' => [...]]]);
     *
     * Calls to an API named in the keys go to a fake host, whatever URL they pass (it may
     * even be unset). Keys that start with http:// or https:// are passed to Http::fake()
     * as they are. Values are anything Http::fake() accepts (array => JSON 200,
     * Http::response(), closure).
     *
     * @param  array<string, mixed>  $stubs
     */
    public function fake(array $stubs = []): void
    {
        $this->config->set('ubo-auth.url', $this->config->get('ubo-auth.url') ?: 'https://api-auth.ubo.test');
        $this->config->set('ubo-auth.client_id', $this->config->get('ubo-auth.client_id') ?: 'fake-client');
        $this->config->set('ubo-auth.client_secret', $this->config->get('ubo-auth.client_secret') ?: 'fake-secret');

        $tokenUrl = rtrim($this->config->get('ubo-auth.url'), '/').$this->config->get('ubo-auth.endpoints.token');

        $fakes = [$tokenUrl => HttpFactory::response([
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'access_token' => 'fake-ubo-token',
        ])];

        foreach ($stubs as $pattern => $response) {
            if (preg_match('#^https?://#', $pattern)) {
                $fakes[$pattern] = $response;

                continue;
            }

            [$name, $path] = explode('/', $pattern, 2) + [1 => ''];
            $this->fakedApis[$name] = "https://{$name}.ubo.test";

            $fakes[$this->fakedApis[$name].'/'.ltrim($path, '/')] = $response;
        }

        $this->http->fake($fakes);
    }

    private function tokens(): ServiceTokenProvider
    {
        if (! $this->container->bound(ServiceTokenProvider::class)) {
            throw new LogicException('The api-client module needs the auth module enabled (ubo-common.modules.auth).');
        }

        return $this->container->make(ServiceTokenProvider::class);
    }

    /**
     * The current trace, or null when the tracing module is disabled.
     */
    private function trace(): ?TraceContext
    {
        return $this->container->bound(TraceContext::class) ? $this->container->make(TraceContext::class) : null;
    }
}
