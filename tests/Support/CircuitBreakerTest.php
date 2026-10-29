<?php

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use UBO\Common\Support\CircuitBreaker;

function circuit(?Repository $cache = null, int $failures = 3, int $cooldown = 30): CircuitBreaker
{
    return new CircuitBreaker('api-x', $cache ?? new Repository(new ArrayStore), 'test:', $failures, $cooldown, Log::getFacadeRoot());
}

function failTimes(CircuitBreaker $circuit, int $times): void
{
    for ($i = 0; $i < $times; $i++) {
        $circuit->recordFailure();
    }
}

test('the circuit opens after the configured failures in a row', function () {
    $circuit = circuit();

    failTimes($circuit, 2);
    expect($circuit->allowsRequest())->toBeTrue();

    $circuit->recordFailure();
    expect($circuit->allowsRequest())->toBeFalse()
        ->and($circuit->isOpen())->toBeTrue();
});

test('a success between failures resets the count', function () {
    $circuit = circuit();

    failTimes($circuit, 2);
    $circuit->recordSuccess();
    failTimes($circuit, 2);

    expect($circuit->allowsRequest())->toBeTrue();
});

test('after the cooldown a single probe goes through', function () {
    $circuit = circuit();
    failTimes($circuit, 3);

    $this->travel(31)->seconds();

    expect($circuit->allowsRequest())->toBeTrue()
        ->and($circuit->allowsRequest())->toBeFalse();
});

test('a successful probe closes the circuit', function () {
    $circuit = circuit();
    failTimes($circuit, 3);
    $this->travel(31)->seconds();
    $circuit->allowsRequest();

    $circuit->recordSuccess();

    expect($circuit->allowsRequest())->toBeTrue()
        ->and($circuit->allowsRequest())->toBeTrue();
});

test('a failed probe opens the circuit for another cooldown', function () {
    $circuit = circuit();
    failTimes($circuit, 3);
    $this->travel(31)->seconds();
    $circuit->allowsRequest();

    $circuit->recordFailure();

    expect($circuit->allowsRequest())->toBeFalse();

    $this->travel(29)->seconds();
    expect($circuit->allowsRequest())->toBeFalse();

    $this->travel(2)->seconds();
    expect($circuit->allowsRequest())->toBeTrue();
});

test('isolated failures far apart never add up', function () {
    $circuit = circuit();

    failTimes($circuit, 2);
    $this->travel(31)->seconds();
    failTimes($circuit, 2);

    expect($circuit->allowsRequest())->toBeTrue();
});

test('circuits with different names are independent', function () {
    $cache = new Repository(new ArrayStore);
    failTimes(circuit($cache), 3);

    $other = new CircuitBreaker('api-y', $cache, 'test:', 3, 30);

    expect($other->allowsRequest())->toBeTrue();
});

test('a broken cache store lets every request through', function () {
    $store = Mockery::mock(Store::class);
    $store->shouldReceive(Mockery::any())->andThrow(new RuntimeException('Redis is down'));
    $circuit = circuit(new Repository($store));

    failTimes($circuit, 5);

    expect($circuit->allowsRequest())->toBeTrue()
        ->and($circuit->isOpen())->toBeFalse();
});

test('opening and closing are logged once, not on every failure', function () {
    Log::spy();
    $circuit = circuit();

    failTimes($circuit, 5);
    $this->travel(31)->seconds();
    $circuit->allowsRequest();
    $circuit->recordSuccess();

    Log::shouldHaveReceived('warning')->once();
    Log::shouldHaveReceived('info')->once();
});

test('the middleware records the outcome of every attempt', function () {
    $circuit = circuit(failures: 2);
    $statuses = [200, 404, 503, 503];
    $calls = 0;
    Http::fake(['https://api-x.test/*' => function (Request $request) use ($statuses, &$calls) {
        return Http::response([], $statuses[$calls++]);
    }]);

    $send = fn () => Http::withMiddleware($circuit->middleware())->get('https://api-x.test/ping');

    $send(); // 200
    $send(); // 404: the service answered
    $send(); // 503
    expect($circuit->allowsRequest())->toBeTrue();

    $send(); // 503: second failure in a row
    expect($circuit->allowsRequest())->toBeFalse();
});

test('the middleware counts a network error as a failure', function () {
    $circuit = circuit(failures: 1);
    Http::fake(['https://api-x.test/*' => connectionRefused()]);

    expect(fn () => Http::withMiddleware($circuit->middleware())->get('https://api-x.test/ping'))
        ->toThrow(ConnectionException::class);

    expect($circuit->allowsRequest())->toBeFalse();
});
