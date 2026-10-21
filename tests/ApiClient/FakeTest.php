<?php

use Illuminate\Support\Facades\Http;
use UBO\Common\ApiClient\Exceptions\UboApiException;
use UBO\Common\ApiClient\UboApi;
use UBO\Common\Tests\TestCase;

test('UboApi::fake() fakes API-Auth and the API by name', function () {
    UboApi::fake([
        'maestros/api/v1/geografia/*' => ['data' => [['cut' => '13101', 'nombre' => 'Santiago']]],
    ]);

    expect(UboApi::call('maestros')->get('/api/v1/geografia/regiones/13/comunas')->json('data.0.nombre'))
        ->toBe('Santiago');
});

test('fake responses can be errors', function () {
    UboApi::fake(['maestros/*' => Http::response(['error' => 'NO_ENCONTRADO'], 404)]);

    UboApi::call('maestros')->get('/api/v1/geografia/comunas/99999');
})->throws(UboApiException::class, 'NO_ENCONTRADO');

test('fake works without any configuration (consumer test suites)', function () {
    TestCase::$bootConfig = [
        'ubo-auth.url' => null,
        'ubo-auth.client_id' => null,
        'ubo-auth.client_secret' => null,
        'ubo-apis.apis.maestros.url' => null,
    ];
    $this->refreshApplication();

    UboApi::fake(['maestros/*' => ['data' => 'ok']]);

    expect(UboApi::callAsync('maestros')->get('/api/v1/geografia/paises')->wait()->json('data'))->toBe('ok');
});
