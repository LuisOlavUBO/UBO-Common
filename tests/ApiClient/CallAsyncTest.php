<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use UBO\Common\ApiClient\Exceptions\UboApiException;
use UBO\Common\ApiClient\UboApi;
use UBO\Common\ApiClient\UboPromise;

test('callAsync returns a promise that resolves to the response', function () {
    Http::fake([...fakeTokenEndpoint(), 'https://maestros.test/*' => Http::response(['data' => 'ok'])]);

    $promise = UboApi::callAsync('maestros')->get('/api/v1/geografia/paises');

    expect($promise)->toBeInstanceOf(UboPromise::class)
        ->and($promise->wait()->json('data'))->toBe('ok');
});

test('all() waits for several calls and keeps their keys', function () {
    Http::fake([
        ...fakeTokenEndpoint(),
        'https://maestros.test/api/v1/geografia/paises' => Http::response(['data' => 'paises']),
        'https://maestros.test/api/v1/geografia/regiones/13/comunas' => Http::response(['data' => 'comunas']),
    ]);

    $responses = UboApi::all([
        'paises' => UboApi::callAsync('maestros')->get('/api/v1/geografia/paises'),
        'comunas' => UboApi::callAsync('maestros')->get('/api/v1/geografia/regiones/13/comunas'),
    ]);

    expect($responses['paises']->json('data'))->toBe('paises')
        ->and($responses['comunas']->json('data'))->toBe('comunas');
});

test('async errors are thrown when waiting', function () {
    Http::fake([...fakeTokenEndpoint(), 'https://maestros.test/*' => Http::response(['error' => 'NO_ENCONTRADO'], 404)]);

    $promise = UboApi::callAsync('maestros')->get('/api/v1/geografia/comunas/99999');

    $promise->wait();
})->throws(UboApiException::class, 'HTTP 404 NO_ENCONTRADO');

test('async calls also renew the token on 401', function () {
    Http::fake([
        'https://api-auth.test/oauth/token' => Http::sequence()
            ->push(['access_token' => 'stale', 'expires_in' => 3600])
            ->push(['access_token' => 'fresh', 'expires_in' => 3600]),
        'https://maestros.test/*' => fn (Request $r) => $r->hasHeader('Authorization', 'Bearer fresh')
            ? Http::response(['data' => 'ok'])
            : Http::response(['error' => 'NO_AUTENTICADO'], 401),
    ]);

    expect(UboApi::callAsync('maestros')->get('/api/v1/geografia/paises')->wait()->json('data'))->toBe('ok');
});

test('async calls retry server errors on GET', function () {
    Http::fake([
        ...fakeTokenEndpoint(),
        'https://maestros.test/*' => Http::sequence()->push([], 503)->push(['data' => 'ok']),
    ]);

    expect(UboApi::callAsync('maestros')->get('/api/v1/geografia/paises')->wait()->json('data'))->toBe('ok');
});

test('async connection failures become UboApiException', function () {
    Http::fake([...fakeTokenEndpoint(), 'https://maestros.test/*' => Http::failedConnection()]);

    try {
        UboApi::callAsync('maestros')->get('/api/v1/geografia/paises')->wait();
        $this->fail('Expected UboApiException');
    } catch (UboApiException $e) {
        expect($e->isConnectionError())->toBeTrue();
    }
});
