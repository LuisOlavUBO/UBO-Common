<?php

namespace UBO\Common\ApiClient;

use Illuminate\Support\Facades\Facade;

/**
 * Entry point to call UBO APIs without handling tokens:
 *
 *     UboApi::call('maestros')->get('/api/v1/geografia/regiones/13/comunas')->json('data');
 *
 *     [$comunas, $paises] = UboApi::all([
 *         UboApi::callAsync('maestros')->get('/api/v1/geografia/regiones/13/comunas'),
 *         UboApi::callAsync('maestros')->get('/api/v1/geografia/paises'),
 *     ]);
 *
 * @method static UboClient call(string $api)
 * @method static UboAsyncClient callAsync(string $api)
 * @method static array<array-key, \Illuminate\Http\Client\Response> all(array<array-key, UboPromise> $promises)
 * @method static ApiDefinition definition(string $api)
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
