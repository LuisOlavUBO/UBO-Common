<?php

namespace UBO\Common\ApiClient;

use GuzzleHttp\Utils;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use LogicException;
use UBO\Common\Auth\Contracts\ServiceTokenProvider;

/**
 * Builds clients for the APIs in config/ubo-apis.php. Used through the UboApi facade.
 */
final class ApiClientManager
{
    /** @var callable|null Guzzle handler shared by async requests so they run concurrently. */
    private $asyncHandler = null;

    public function __construct(
        private readonly Config $config,
        private readonly HttpFactory $http,
        private readonly Container $container,
    ) {}

    public function call(string $api): UboClient
    {
        return new UboClient($this->definition($api), $this->http, $this->tokens());
    }

    public function callAsync(string $api): UboAsyncClient
    {
        $this->asyncHandler ??= Utils::chooseHandler();

        return new UboAsyncClient($this->definition($api), $this->http, $this->tokens(), $this->asyncHandler);
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

    public function definition(string $api): ApiDefinition
    {
        return ApiDefinition::fromConfig($api, (array) $this->config->get('ubo-apis', []));
    }

    /**
     * Fake API-Auth and the UBO APIs in tests. Keys start with the API name:
     *
     *     UboApi::fake(['maestros/api/v1/geografia/*' => ['data' => [...]]]);
     *
     * Values are anything Http::fake() accepts (array => JSON 200, Http::response(), closure).
     * Keys that do not start with a known API name are passed to Http::fake() as they are.
     *
     * @param  array<string, mixed>  $stubs
     */
    public function fake(array $stubs = []): void
    {
        $this->config->set('ubo-auth.url', $this->config->get('ubo-auth.url') ?: 'https://api-auth.ubo.test');
        $this->config->set('ubo-auth.client_id', $this->config->get('ubo-auth.client_id') ?: 'fake-client');
        $this->config->set('ubo-auth.client_secret', $this->config->get('ubo-auth.client_secret') ?: 'fake-secret');

        foreach (array_keys((array) $this->config->get('ubo-apis.apis', [])) as $name) {
            if (blank($this->config->get("ubo-apis.apis.{$name}.url"))) {
                $this->config->set("ubo-apis.apis.{$name}.url", "https://{$name}.ubo.test");
            }
        }

        $tokenUrl = rtrim($this->config->get('ubo-auth.url'), '/').$this->config->get('ubo-auth.endpoints.token');

        $fakes = [$tokenUrl => HttpFactory::response([
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'access_token' => 'fake-ubo-token',
        ])];

        foreach ($stubs as $pattern => $response) {
            [$name, $path] = explode('/', $pattern, 2) + [1 => ''];
            $url = $this->config->get("ubo-apis.apis.{$name}.url");

            $fakes[$url ? rtrim($url, '/').'/'.ltrim($path, '/') : $pattern] = $response;
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
}
