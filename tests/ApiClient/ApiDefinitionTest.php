<?php

use UBO\Common\ApiClient\ApiDefinition;

test('an API with its own values uses them, the others use the defaults', function () {
    $config = ['defaults' => ['timeout' => 10], 'apis' => ['api-reportes' => ['timeout' => 30]]];

    expect(ApiDefinition::fromConfig('api-reportes', 'https://r.test', $config)->timeout)->toBe(30)
        ->and(ApiDefinition::fromConfig('api-maestros', 'https://m.test', $config)->timeout)->toBe(10);
});

test('an API with some values takes every other one from the defaults', function () {
    $definition = ApiDefinition::fromConfig('api-reportes', 'https://r.test', [
        'defaults' => [
            'retries' => 3,
            'retry_delay' => 100,
            'deadline' => 20,
            'circuit_breaker' => ['enabled' => true, 'failures' => 7, 'cooldown' => 10],
        ],
        'apis' => ['api-reportes' => ['timeout' => 30, 'circuit_breaker' => ['cooldown' => 60]]],
    ]);

    expect($definition->timeout)->toBe(30)
        ->and($definition->retries)->toBe(3)
        ->and($definition->retryDelay)->toBe(100)
        ->and($definition->deadline)->toBe(20)
        ->and($definition->circuitEnabled)->toBeTrue()
        ->and($definition->circuitFailures)->toBe(7)
        ->and($definition->circuitCooldown)->toBe(60);
});

test('a config published with 3.0.0 gets the package values for the new keys', function () {
    $definition = ApiDefinition::fromConfig('api-maestros', 'https://m.test', [
        'defaults' => ['timeout' => 10, 'connect_timeout' => 5, 'retries' => 2, 'retry_delay' => 200, 'headers' => []],
    ]);

    expect($definition->deadline)->toBe(30)
        ->and($definition->retryMaxDelay)->toBe(2000)
        ->and($definition->circuitEnabled)->toBeTrue()
        ->and($definition->circuitFailures)->toBe(5)
        ->and($definition->circuitCooldown)->toBe(30);
});

test('an invalid value falls back to the defaults, then to the package', function () {
    $definition = ApiDefinition::fromConfig('api-x', 'https://x.test', [
        'defaults' => ['timeout' => 'abc', 'deadline' => 12],
        'apis' => ['api-x' => ['timeout' => '', 'deadline' => 0]],
    ]);

    expect($definition->timeout)->toBe(10)
        ->and($definition->deadline)->toBe(12);
});

test('the circuit can be disabled for one API', function () {
    $config = ['apis' => ['api-legado' => ['circuit_breaker' => ['enabled' => false]]]];

    expect(ApiDefinition::fromConfig('api-legado', 'https://l.test', $config)->circuitEnabled)->toBeFalse()
        ->and(ApiDefinition::fromConfig('api-maestros', 'https://m.test', $config)->circuitEnabled)->toBeTrue();
});

test('the wait before each retry doubles, is capped and varies between half and all of it', function () {
    $definition = new ApiDefinition('api-x', 'https://x.test', 10, 5, 6, 200, [], retryMaxDelay: 2000);

    foreach (range(1, 50) as $ignored) {
        expect($definition->retryWait(1))->toBeGreaterThanOrEqual(100)->toBeLessThanOrEqual(200)
            ->and($definition->retryWait(2))->toBeGreaterThanOrEqual(200)->toBeLessThanOrEqual(400)
            ->and($definition->retryWait(6))->toBeGreaterThanOrEqual(1000)->toBeLessThanOrEqual(2000);
    }

    $waits = array_map(fn () => $definition->retryWait(3), range(1, 50));
    expect(count(array_unique($waits)))->toBeGreaterThan(1);
});

test('without a retry delay there is no wait', function () {
    expect((new ApiDefinition('api-x', 'https://x.test', 10, 5, 2, 0, []))->retryWait(3))->toBe(0);
});
