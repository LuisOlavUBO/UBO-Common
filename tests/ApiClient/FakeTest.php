<?php

use Illuminate\Support\Facades\Http;
use UBO\Common\ApiClient\Exceptions\UboApiException;
use UBO\Common\ApiClient\UboApi;
use UBO\Common\Tests\TestCase;

test('UboApi::fake() fakes API-Auth and the API by name', function () {
    UboApi::fake([
        'api-maestros/api/maestros/v1/geografia/*' => ['data' => [['cut' => '13101', 'nombre' => 'Santiago']]],
    ]);

    expect(UboApi::call('api-maestros', 'https://maestros.ubo.cl')->get('/api/maestros/v1/geografia/regiones/13/comunas')->json('data.0.nombre'))
        ->toBe('Santiago');
});

test('fake responses can be errors', function () {
    UboApi::fake(['api-maestros/*' => Http::response(['error' => 'NO_ENCONTRADO'], 404)]);

    UboApi::call('api-maestros', 'https://maestros.ubo.cl')->get('/api/maestros/v1/geografia/comunas/99999');
})->throws(UboApiException::class, 'NO_ENCONTRADO');

test('fake keys can also be full URLs', function () {
    UboApi::fake(['https://maestros.ubo.cl/api/maestros/v1/*' => ['data' => 'ok']]);

    expect(UboApi::call('api-maestros', 'https://maestros.ubo.cl')->get('/api/maestros/v1/geografia/paises')->json('data'))->toBe('ok');
});

test('fake works without any configuration (consumer test suites)', function () {
    TestCase::$bootConfig = [
        'ubo-auth.url' => null,
        'ubo-auth.client_id' => null,
        'ubo-auth.client_secret' => null,
    ];
    $this->refreshApplication();

    UboApi::fake(['api-maestros/*' => ['data' => 'ok']]);

    expect(UboApi::callAsync('api-maestros', config('services.maestros.url'))->get('/api/maestros/v1/geografia/paises')->wait()->json('data'))->toBe('ok');
});
