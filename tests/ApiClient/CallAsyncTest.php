<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use UBO\Common\ApiClient\Exceptions\UboApiException;
use UBO\Common\ApiClient\UboApi;
use UBO\Common\ApiClient\UboPromise;

/*
| Fails with $failure, and waits for every promise expecting a deadline exceeded.
*/
function expectDeadlineExceeded(UboPromise ...$promises): void
{
    foreach ($promises as $promise) {
        try {
            $promise->wait();
            test()->fail('Expected UboApiException');
        } catch (UboApiException $e) {
            expect($e->isDeadlineExceeded())->toBeTrue();
        }
    }
}

test('an async call stops being retried when its deadline is reached', function () {
    config(['ubo-apis.defaults.timeout' => 10, 'ubo-apis.defaults.retries' => 2, 'ubo-apis.defaults.deadline' => 15]);
    $timeouts = [];
    Http::fake([...fakeTokenEndpoint(), 'https://maestros.test/*' => slowTimeout($timeouts)]);

    expectDeadlineExceeded(UboApi::callAsync('api-maestros', 'https://maestros.test')->get('/api/maestros/v1/geografia/paises'));

    expect($timeouts)->toBe([10.0, 5.0]);
});

test('parallel calls count their deadline from their own start, not one after the other', function () {
    config(['ubo-apis.defaults.timeout' => 10, 'ubo-apis.defaults.retries' => 2, 'ubo-apis.defaults.deadline' => 15]);
    Http::fake([...fakeTokenEndpoint(), 'https://maestros.test/*' => slowTimeout()]);
    $start = now();

    expectDeadlineExceeded(
        UboApi::callAsync('api-maestros', 'https://maestros.test')->get('/api/maestros/v1/geografia/paises'),
        UboApi::callAsync('api-maestros', 'https://maestros.test')->get('/api/maestros/v1/geografia/regiones'),
    );

    // Http::fake() runs each attempt in turn, moving one shared clock: with the deadline
    // counted per call, the pair still ends well before two full deadlines (30 s).
    expect($start->diffInSeconds(now()))->toBeLessThan(30);
});

test('callAsync returns a promise that resolves to the response', function () {
    Http::fake([...fakeTokenEndpoint(), 'https://maestros.test/*' => Http::response(['data' => 'ok'])]);

    $promise = UboApi::callAsync('api-maestros', 'https://maestros.test')->get('/api/maestros/v1/geografia/paises');

    expect($promise)->toBeInstanceOf(UboPromise::class)
        ->and($promise->wait()->json('data'))->toBe('ok');
});

test('all() waits for several calls and keeps their keys', function () {
    Http::fake([
        ...fakeTokenEndpoint(),
        'https://maestros.test/api/maestros/v1/geografia/paises' => Http::response(['data' => 'paises']),
        'https://maestros.test/api/maestros/v1/geografia/regiones/13/comunas' => Http::response(['data' => 'comunas']),
    ]);

    $responses = UboApi::all([
        'paises' => UboApi::callAsync('api-maestros', 'https://maestros.test')->get('/api/maestros/v1/geografia/paises'),
        'comunas' => UboApi::callAsync('api-maestros', 'https://maestros.test')->get('/api/maestros/v1/geografia/regiones/13/comunas'),
    ]);

    expect($responses['paises']->json('data'))->toBe('paises')
        ->and($responses['comunas']->json('data'))->toBe('comunas');
});

test('async errors are thrown when waiting', function () {
    Http::fake([...fakeTokenEndpoint(), 'https://maestros.test/*' => Http::response(['error' => 'NO_ENCONTRADO'], 404)]);

    $promise = UboApi::callAsync('api-maestros', 'https://maestros.test')->get('/api/maestros/v1/geografia/comunas/99999');

    $promise->wait();
})->throws(UboApiException::class, 'HTTP 404 NO_ENCONTRADO');

test('async calls also renew the token on 401', function () {
    Http::fake([
        'https://api-auth.test/api/auth/oauth/token' => Http::sequence()
            ->push(['access_token' => 'stale', 'expires_in' => 3600])
            ->push(['access_token' => 'fresh', 'expires_in' => 3600]),
        'https://maestros.test/*' => fn (Request $r) => $r->hasHeader('Authorization', 'Bearer fresh')
            ? Http::response(['data' => 'ok'])
            : Http::response(['error' => 'NO_AUTENTICADO'], 401),
    ]);

    expect(UboApi::callAsync('api-maestros', 'https://maestros.test')->get('/api/maestros/v1/geografia/paises')->wait()->json('data'))->toBe('ok');
});

test('async calls retry server errors on GET', function () {
    Http::fake([
        ...fakeTokenEndpoint(),
        'https://maestros.test/*' => Http::sequence()->push([], 503)->push(['data' => 'ok']),
    ]);

    expect(UboApi::callAsync('api-maestros', 'https://maestros.test')->get('/api/maestros/v1/geografia/paises')->wait()->json('data'))->toBe('ok');
});

// Laravel does not record failed async attempts, so these tests count the calls themselves.

test('an async POST that timed out reading the response is not sent again', function () {
    $attempts = 0;
    Http::fake([...fakeTokenEndpoint(), 'https://maestros.test/*' => function (Request $r) use (&$attempts) {
        $attempts++;

        return readTimeout()($r);
    }]);

    $promise = UboApi::callAsync('api-maestros', 'https://maestros.test')->post('/api/maestros/v1/homologaciones/traducir-lote', []);

    expect(fn () => $promise->wait())->toThrow(UboApiException::class)
        ->and($attempts)->toBe(1);
});

test('an async POST that could not connect is retried', function () {
    $attempts = 0;
    $respond = failThen(connectionRefused(), 1, ['data' => 'creado'], 201);
    Http::fake([...fakeTokenEndpoint(), 'https://maestros.test/*' => function (Request $r) use (&$attempts, $respond) {
        $attempts++;

        return $respond($r);
    }]);

    $promise = UboApi::callAsync('api-maestros', 'https://maestros.test')->post('/api/maestros/v1/homologaciones/traducir-lote', []);

    expect($promise->wait()->status())->toBe(201)
        ->and($attempts)->toBe(2);
});

test('async connection failures become UboApiException', function () {
    Http::fake([...fakeTokenEndpoint(), 'https://maestros.test/*' => Http::failedConnection()]);

    try {
        UboApi::callAsync('api-maestros', 'https://maestros.test')->get('/api/maestros/v1/geografia/paises')->wait();
        $this->fail('Expected UboApiException');
    } catch (UboApiException $e) {
        expect($e->isConnectionError())->toBeTrue();
    }
});
