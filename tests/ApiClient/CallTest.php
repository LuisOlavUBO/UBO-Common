<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use UBO\Common\ApiClient\Exceptions\UboApiException;
use UBO\Common\ApiClient\UboApi;

test('it calls the API with its base URL and an API-Auth token', function () {
    Http::fake([
        ...fakeTokenEndpoint('consumer-token'),
        'https://maestros.test/api/maestros/v1/geografia/regiones/13/comunas' => Http::response([
            'data' => [['cut' => '13101', 'nombre' => 'Santiago']],
        ]),
    ]);

    $comunas = UboApi::call('api-maestros', 'https://maestros.test')->get('/api/maestros/v1/geografia/regiones/13/comunas')->json('data');

    expect($comunas)->toBe([['cut' => '13101', 'nombre' => 'Santiago']]);

    Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://maestros.test/')
        && $r->hasHeader('Authorization', 'Bearer consumer-token')
        && $r->hasHeader('Accept', 'application/json'));

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/auth/oauth/token')
        && $r['api'] === 'api-maestros'
        && ! isset($r['scope']));
});

test('the token is reused across calls', function () {
    Http::fake([...fakeTokenEndpoint(), 'https://maestros.test/*' => Http::response(['data' => []])]);

    UboApi::call('api-maestros', 'https://maestros.test')->get('/api/maestros/v1/geografia/paises');
    UboApi::call('api-maestros', 'https://maestros.test')->get('/api/maestros/v1/geografia/paises');

    Http::assertSentCount(3); // 1 token + 2 calls
});

test('query strings, bodies and extra configuration are passed through', function () {
    Http::fake([...fakeTokenEndpoint(), 'https://maestros.test/*' => Http::response(['data' => []])]);

    UboApi::call('api-maestros', 'https://maestros.test')->get('/api/maestros/v1/geografia/paises', ['incluir_inactivos' => 1]);
    UboApi::call('api-maestros', 'https://maestros.test')->withHeaders(['X-Request-Id' => 'abc'])->post('/api/maestros/v1/homologaciones/traducir-lote', ['codigos' => ['1']]);

    Http::assertSent(fn (Request $r) => $r->url() === 'https://maestros.test/api/maestros/v1/geografia/paises?incluir_inactivos=1');
    Http::assertSent(fn (Request $r) => $r->method() === 'POST'
        && ($r->data()['codigos'] ?? null) === ['1']
        && $r->hasHeader('X-Request-Id', 'abc'));
});

test('a 401 renews the token and retries once', function () {
    Http::fake([
        'https://api-auth.test/api/auth/oauth/token' => Http::sequence()
            ->push(['access_token' => 'stale', 'expires_in' => 3600])
            ->push(['access_token' => 'fresh', 'expires_in' => 3600]),
        'https://maestros.test/*' => fn (Request $r) => $r->hasHeader('Authorization', 'Bearer fresh')
            ? Http::response(['data' => 'ok'])
            : Http::response(['error' => 'NO_AUTENTICADO'], 401),
    ]);

    expect(UboApi::call('api-maestros', 'https://maestros.test')->get('/api/maestros/v1/geografia/paises')->json('data'))->toBe('ok');
});

test('a persistent 401 ends in an exception', function () {
    Http::fake([...fakeTokenEndpoint(), 'https://maestros.test/*' => Http::response(['error' => 'NO_AUTENTICADO'], 401)]);

    UboApi::call('api-maestros', 'https://maestros.test')->get('/api/maestros/v1/geografia/paises');
})->throws(UboApiException::class, 'HTTP 401 NO_AUTENTICADO');

test('server errors on GET are retried', function () {
    Http::fake([
        ...fakeTokenEndpoint(),
        'https://maestros.test/*' => Http::sequence()
            ->push(['error' => 'ERROR_INTERNO'], 503)
            ->push(['error' => 'ERROR_INTERNO'], 502)
            ->push(['data' => 'ok']),
    ]);

    expect(UboApi::call('api-maestros', 'https://maestros.test')->get('/api/maestros/v1/geografia/paises')->json('data'))->toBe('ok');
});

test('server errors on POST are not retried (not idempotent)', function () {
    Http::fake([
        ...fakeTokenEndpoint(),
        'https://maestros.test/*' => Http::sequence()
            ->push(['error' => 'ERROR_INTERNO'], 503)
            ->push(['data' => 'ok']),
    ]);

    expect(fn () => UboApi::call('api-maestros', 'https://maestros.test')->post('/api/maestros/v1/homologaciones/traducir-lote', []))
        ->toThrow(UboApiException::class);

    Http::assertSentCount(2); // 1 token + 1 call
});

test('retries stop after the configured number', function () {
    config(['ubo-apis.defaults.retries' => 1]);
    Http::fake([...fakeTokenEndpoint(), 'https://maestros.test/*' => Http::response([], 503)]);

    expect(fn () => UboApi::call('api-maestros', 'https://maestros.test')->get('/api/maestros/v1/geografia/paises'))->toThrow(UboApiException::class);

    Http::assertSentCount(3); // 1 token + 2 attempts
});

test('client errors expose the RFC 9457 problem', function () {
    Http::fake([...fakeTokenEndpoint(), 'https://maestros.test/*' => Http::response([
        'type' => 'about:blank',
        'title' => 'Scope insuficiente',
        'status' => 403,
        'detail' => 'El token no tiene el scope requerido: sistemas:leer.',
        'error' => 'SCOPE_INSUFICIENTE',
    ], 403, ['Content-Type' => 'application/problem+json'])]);

    try {
        UboApi::call('api-maestros', 'https://maestros.test')->get('/api/maestros/v1/sistemas');
        $this->fail('Expected UboApiException');
    } catch (UboApiException $e) {
        expect($e->api)->toBe('api-maestros')
            ->and($e->status)->toBe(403)
            ->and($e->errorCode)->toBe('SCOPE_INSUFICIENTE')
            ->and($e->title)->toBe('Scope insuficiente')
            ->and($e->detail)->toContain('sistemas:leer')
            ->and($e->isConnectionError())->toBeFalse()
            ->and($e->getMessage())->toBe('[api-maestros] HTTP 403 SCOPE_INSUFICIENTE: El token no tiene el scope requerido: sistemas:leer.');
    }

    Http::assertSentCount(2); // 4xx are never retried
});

test('connection failures are retried and then reported', function () {
    Http::fake([...fakeTokenEndpoint(), 'https://maestros.test/*' => Http::failedConnection()]);

    try {
        UboApi::call('api-maestros', 'https://maestros.test')->get('/api/maestros/v1/geografia/paises');
        $this->fail('Expected UboApiException');
    } catch (UboApiException $e) {
        expect($e->isConnectionError())->toBeTrue()
            ->and($e->status)->toBeNull();
    }

    Http::assertSentCount(4); // 1 token + 3 attempts
});

test('a POST that timed out reading the response is not sent again', function () {
    Http::fake([...fakeTokenEndpoint(), 'https://maestros.test/*' => readTimeout()]);

    try {
        UboApi::call('api-maestros', 'https://maestros.test')->post('/api/maestros/v1/homologaciones/traducir-lote', ['codigos' => ['1']]);
        $this->fail('Expected UboApiException');
    } catch (UboApiException $e) {
        expect($e->isConnectionError())->toBeTrue();
    }

    Http::assertSentCount(2); // 1 token + 1 call
});

test('a POST that could not connect is retried', function () {
    Http::fake([...fakeTokenEndpoint(), 'https://maestros.test/*' => failThen(connectionRefused(), 1, ['data' => 'creado'], 201)]);

    $response = UboApi::call('api-maestros', 'https://maestros.test')->post('/api/maestros/v1/homologaciones/traducir-lote', ['codigos' => ['1']]);

    expect($response->status())->toBe(201);
    Http::assertSentCount(3); // 1 token + 2 attempts
});

test('a PATCH that timed out connecting is retried up to the configured number', function () {
    Http::fake([...fakeTokenEndpoint(), 'https://maestros.test/*' => connectTimeout()]);

    expect(fn () => UboApi::call('api-maestros', 'https://maestros.test')->patch('/api/maestros/v1/sistemas/SIS', ['nombre' => 'x']))
        ->toThrow(UboApiException::class);

    Http::assertSentCount(4); // 1 token + 3 attempts
});

test('a POST whose connection error cannot be classified is not sent again', function () {
    Http::fake([...fakeTokenEndpoint(), 'https://maestros.test/*' => unclassifiedConnectionError()]);

    expect(fn () => UboApi::call('api-maestros', 'https://maestros.test')->post('/api/maestros/v1/homologaciones/traducir-lote', []))
        ->toThrow(UboApiException::class);

    Http::assertSentCount(2); // 1 token + 1 call
});

test('a GET that timed out reading the response is still retried', function () {
    Http::fake([...fakeTokenEndpoint(), 'https://maestros.test/*' => failThen(readTimeout(), 1, ['data' => 'ok'])]);

    expect(UboApi::call('api-maestros', 'https://maestros.test')->get('/api/maestros/v1/geografia/paises')->json('data'))->toBe('ok');

    Http::assertSentCount(3); // 1 token + 2 attempts
});

test('if API-Auth refuses the token the API is never called', function () {
    Http::fake([
        'https://api-auth.test/api/auth/oauth/token' => Http::response(['error' => 'invalid_client'], 401),
        'https://maestros.test/*' => Http::response(['data' => []]),
    ]);

    expect(fn () => UboApi::call('api-maestros', 'https://maestros.test')->get('/api/maestros/v1/geografia/paises'))
        ->toThrow(UboApiException::class, 'could not get a token from API-Auth');

    Http::assertNotSent(fn (Request $r) => str_starts_with($r->url(), 'https://maestros.test'));
});

test('each API gets its own token, and calling one does not replace the other', function () {
    Http::fake([
        'https://api-auth.test/api/auth/oauth/token' => fn (Request $r) => Http::response([
            'access_token' => "token-for-{$r['api']}",
            'expires_in' => 3600,
        ]),
        'https://maestros.test/*' => Http::response(['data' => []]),
        'https://matricula.test/*' => Http::response(['data' => []]),
    ]);

    UboApi::call('api-maestros', 'https://maestros.test')->get('/api/maestros/v1/geografia/paises');
    UboApi::call('api-matricula', 'https://matricula.test')->get('/api/matricula/v1/alumnos');
    UboApi::call('api-maestros', 'https://maestros.test')->get('/api/maestros/v1/geografia/paises');

    Http::assertSentCount(5); // 2 tokens + 3 calls
    Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://matricula.test/')
        && $r->hasHeader('Authorization', 'Bearer token-for-api-matricula'));
});

test('a call without URL says so', function () {
    UboApi::call('api-maestros', config('services.maestros.url'));
})->throws(InvalidArgumentException::class, 'API [api-maestros] was called without a URL');
