<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use UBO\Common\ApiClient\ApiClientManager;
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

test('fake answers the token route the library uses, also when it is replaced', function () {
    config(['ubo-auth.endpoint_overrides.token' => '/custom/token']);

    UboApi::fake(['api-maestros/*' => ['data' => 'ok']]);

    expect(UboApi::call('api-maestros', null)->get('/api/maestros/v1/geografia/paises')->json('data'))->toBe('ok');

    Http::assertSent(fn (Request $r) => $r->url() === 'https://api-auth.test/custom/token');
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

test('fake starts with every circuit closed', function () {
    $manager = app(ApiClientManager::class);
    $circuit = $manager->circuit($manager->definition('api-maestros', 'https://maestros.test'));

    for ($i = 0; $i < 5; $i++) {
        $circuit->recordFailure();
    }

    UboApi::fake(['api-maestros/*' => ['data' => 'ok']]);

    expect(UboApi::call('api-maestros', null)->get('/api/maestros/v1/geografia/paises')->json('data'))->toBe('ok');
});
