<?php

namespace UBO\Common\ApiClient;

use Illuminate\Support\Facades\Facade;

/**
 * Entry point to call UBO APIs without handling tokens:
 *
 *     UboApi::call('api-maestros', $url)->get('/api/maestros/v1/geografia/regiones/13/comunas')->json('data');
 *
 *     [$comunas, $paises] = UboApi::all([
 *         UboApi::callAsync('api-maestros', $url)->get('/api/maestros/v1/geografia/regiones/13/comunas'),
 *         UboApi::callAsync('api-maestros', $url)->get('/api/maestros/v1/geografia/paises'),
 *     ]);
 *
 * @method static UboClient call(string $api, ?string $url)
 * @method static UboAsyncClient callAsync(string $api, ?string $url)
 * @method static array<array-key, \Illuminate\Http\Client\Response> all(array<array-key, UboPromise> $promises)
 * @method static ApiDefinition definition(string $api, ?string $url)
 * @method static void fake(array<string, mixed> $stubs = [])
 *
 * @see ApiClientManager
 */
final class UboApi extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ApiClientManager::class;
    }
}
