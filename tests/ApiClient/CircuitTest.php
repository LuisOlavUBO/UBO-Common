<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use UBO\Common\ApiClient\ApiClientManager;
use UBO\Common\ApiClient\Exceptions\UboApiException;
use UBO\Common\ApiClient\UboApi;

beforeEach(function () {
    config(['ubo-apis.defaults.retries' => 0]);
});

/*
| Makes $times calls to api-maestros that fail, so its circuit counts $times failures.
*/
function failMaestros(int $times): void
{
    for ($i = 0; $i < $times; $i++) {
        try {
            UboApi::call('api-maestros', 'https://maestros.test')->get('/api/maestros/v1/geografia/paises');
        } catch (UboApiException) {
        }
    }
}

function maestrosCalls(): int
{
    return count(Http::recorded(fn (Request $request) => str_starts_with($request->url(), 'https://maestros.test/')));
}

test('after five failed attempts the API is not called and the call fails at once', function () {
    Http::fake([...fakeTokenEndpoint(), 'https://maestros.test/*' => connectionRefused()]);
    failMaestros(5);

    try {
        UboApi::call('api-maestros', 'https://maestros.test')->get('/api/maestros/v1/geografia/paises');
        $this->fail('Expected UboApiException');
    } catch (UboApiException $e) {
        expect($e->isCircuitOpen())->toBeTrue()
            ->and($e->isConnectionError())->toBeFalse()
            ->and($e->status)->toBeNull();
    }

    expect(maestrosCalls())->toBe(5);
});

test('with the circuit open no token is requested', function () {
    Http::fake();
    $manager = app(ApiClientManager::class);
    $circuit = $manager->circuit($manager->definition('api-maestros', 'https://maestros.test'));

    for ($i = 0; $i < 5; $i++) {
        $circuit->recordFailure();
    }

    failMaestros(1);

    Http::assertNothingSent();
});

test('5xx answers count as failures, on any method', function () {
    Http::fake([...fakeTokenEndpoint(), 'https://maestros.test/*' => Http::response([], 503)]);

    for ($i = 0; $i < 5; $i++) {
        try {
            UboApi::call('api-maestros', 'https://maestros.test')->post('/api/maestros/v1/homologaciones/traducir-lote');
        } catch (UboApiException) {
        }
    }

    expect(fn () => UboApi::call('api-maestros', 'https://maestros.test')->get('/api/maestros/v1/geografia/paises'))
        ->toThrow(fn (UboApiException $e) => expect($e->isCircuitOpen())->toBeTrue());
});

test('the circuit of one API does not affect another', function () {
    Http::fake([
        ...fakeTokenEndpoint(),
        'https://maestros.test/*' => connectionRefused(),
        'https://matricula.test/*' => Http::response(['data' => []]),
    ]);
    failMaestros(5);

    expect(UboApi::call('api-matricula', 'https://matricula.test')->get('/api/matricula/v1/alumnos')->successful())->toBeTrue();
});

test('after the cooldown a successful probe closes the circuit', function () {
    Http::fake([...fakeTokenEndpoint(), 'https://maestros.test/*' => failThen(connectionRefused(), 5, ['data' => 'ok'])]);
    failMaestros(5);

    $this->travel(31)->seconds();

    expect(UboApi::call('api-maestros', 'https://maestros.test')->get('/api/maestros/v1/geografia/paises')->json('data'))->toBe('ok')
        ->and(UboApi::call('api-maestros', 'https://maestros.test')->get('/api/maestros/v1/geografia/paises')->json('data'))->toBe('ok');
});

test('after the cooldown a failed probe opens the circuit for another cooldown', function () {
    Http::fake([...fakeTokenEndpoint(), 'https://maestros.test/*' => connectionRefused()]);
    failMaestros(5);
    $this->travel(31)->seconds();
    failMaestros(1); // the probe

    failMaestros(1);

    expect(maestrosCalls())->toBe(6);
});

test('client errors never open the circuit', function () {
    Http::fake([...fakeTokenEndpoint(), 'https://maestros.test/*' => Http::response(['error' => 'NO_ENCONTRADO'], 404)]);

    failMaestros(7);

    expect(maestrosCalls())->toBe(7);
});

test('an async call to an API with the circuit open sends nothing and fails when waited', function () {
    Http::fake([...fakeTokenEndpoint(), 'https://maestros.test/*' => connectionRefused()]);
    failMaestros(5);

    $promise = UboApi::callAsync('api-maestros', 'https://maestros.test')->get('/api/maestros/v1/geografia/paises');

    expect(fn () => $promise->wait())
        ->toThrow(fn (UboApiException $e) => expect($e->isCircuitOpen())->toBeTrue());
    expect(maestrosCalls())->toBe(5);
});

test('async failures also count for the circuit', function () {
    Http::fake([...fakeTokenEndpoint(), 'https://maestros.test/*' => connectionRefused()]);

    for ($i = 0; $i < 5; $i++) {
        try {
            UboApi::callAsync('api-maestros', 'https://maestros.test')->get('/api/maestros/v1/geografia/paises')->wait();
        } catch (UboApiException) {
        }
    }

    expect(fn () => UboApi::call('api-maestros', 'https://maestros.test')->get('/api/maestros/v1/geografia/paises'))
        ->toThrow(fn (UboApiException $e) => expect($e->isCircuitOpen())->toBeTrue());
});

test('an API with the circuit disabled is always called', function (string $scope) {
    config([$scope.'.circuit_breaker.enabled' => false]);
    Http::fake([...fakeTokenEndpoint(), 'https://maestros.test/*' => connectionRefused()]);

    failMaestros(8);

    expect(maestrosCalls())->toBe(8);
})->with([
    'for that API' => ['ubo-apis.apis.api-maestros'],
    'for every API' => ['ubo-apis.defaults'],
]);

test('the circuits live in the ubo-apis cache store', function () {
    config(['cache.stores.circuits' => ['driver' => 'array'], 'ubo-apis.cache_store' => 'circuits']);
    Http::fake([...fakeTokenEndpoint(), 'https://maestros.test/*' => connectionRefused()]);
    failMaestros(5);

    expect(cache()->store('circuits')->has('ubo-apis:circuit:api-maestros:open'))->toBeTrue()
        ->and(cache()->has('ubo-apis:circuit:api-maestros:open'))->toBeFalse();
});
