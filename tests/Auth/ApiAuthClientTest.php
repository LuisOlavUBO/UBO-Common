<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use UBO\Common\Auth\Infrastructure\ApiAuthClient;
use UBO\Common\Auth\Infrastructure\ApiAuthUnreachable;

function openApiAuthCircuit(): void
{
    for ($i = 0; $i < 5; $i++) {
        app(ApiAuthClient::class)->circuit()->recordFailure();
    }
}

test('with the circuit open nothing is sent to API-Auth', function () {
    Http::fake();
    openApiAuthCircuit();

    try {
        app(ApiAuthClient::class)->send(fn ($request) => $request->get('https://api-auth.test/api/auth/v1/public-key'));
        $this->fail('ApiAuthUnreachable was not thrown');
    } catch (ApiAuthUnreachable $e) {
        expect($e->isCircuitOpen())->toBeTrue()
            ->and($e->getMessage())->toContain('circuit open');
    }

    Http::assertNothingSent();
});

test('a 4xx is an answer, not a failure of API-Auth', function () {
    Http::fake(['https://api-auth.test/*' => Http::response([], 400)]);

    for ($i = 0; $i < 6; $i++) {
        $response = app(ApiAuthClient::class)->send(fn ($request) => $request->get('https://api-auth.test/api/auth/v1/public-key'));
    }

    expect($response->status())->toBe(400)
        ->and(app(ApiAuthClient::class)->circuit()->allowsRequest())->toBeTrue();
    Http::assertSentCount(6);
});

test('a 5xx and a connection error are failures of API-Auth', function () {
    Http::fake(['https://api-auth.test/api/auth/v1/public-key' => Http::response('', 503)]);

    expect(fn () => app(ApiAuthClient::class)->send(fn ($request) => $request->get('https://api-auth.test/api/auth/v1/public-key')))
        ->toThrow(ApiAuthUnreachable::class, 'API-Auth answered 503.');

    Http::fake(['https://api-auth.test/api/auth/v1/introspect' => connectionRefused()]);

    expect(fn () => app(ApiAuthClient::class)->send(fn ($request) => $request->post('https://api-auth.test/api/auth/v1/introspect')))
        ->toThrow(ApiAuthUnreachable::class, 'could not be reached');
});

test('without the circuit API-Auth is always called and the circuit is left as it is', function () {
    Http::fake(['https://api-auth.test/*' => Http::response('ok')]);
    openApiAuthCircuit();

    $response = app(ApiAuthClient::class)->withoutCircuit()->send(fn ($request) => $request->get('https://api-auth.test/api/auth/v1/public-key'));

    expect($response->body())->toBe('ok')
        ->and(app(ApiAuthClient::class)->circuit()->isOpen())->toBeTrue();
});

test('every call uses the connect timeout and the timeout', function () {
    config(['ubo-auth.timeout' => 4, 'ubo-auth.connect_timeout' => 1]);
    $options = [];
    Http::fake(function (Request $request, array $sent) use (&$options) {
        $options = $sent;

        return Http::response('ok');
    });

    app(ApiAuthClient::class)->send(fn ($request) => $request->get('https://api-auth.test/api/auth/v1/public-key'));

    expect($options['connect_timeout'])->toBe(1)
        ->and($options['timeout'])->toBe(4);
});
