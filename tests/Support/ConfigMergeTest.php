<?php

use UBO\Common\Support\ConfigMerge;

test('a nested key the application lacks comes from the package', function () {
    $merged = ConfigMerge::deep(
        ['cache' => ['prefix' => 'ubo-auth:', 'service_token_margin' => 60]],
        ['cache' => ['prefix' => 'app:']],
    );

    expect($merged)->toBe(['cache' => ['prefix' => 'app:', 'service_token_margin' => 60]]);
});

test('a key the application sets keeps its value, even null', function () {
    $merged = ConfigMerge::deep(
        ['url' => 'https://api-auth.ubo.cl', 'timeout' => 5],
        ['url' => null],
    );

    expect($merged)->toBe(['url' => null, 'timeout' => 5]);
});

test('lists and empty arrays are replaced whole', function () {
    $merged = ConfigMerge::deep(
        ['routes' => ['api/*', 'interno/*'], 'headers' => [], 'scopes' => ['a']],
        ['routes' => ['otro/*'], 'headers' => ['X-Version' => '2'], 'scopes' => []],
    );

    expect($merged)->toBe(['routes' => ['otro/*'], 'headers' => ['X-Version' => '2'], 'scopes' => []]);
});

test('it merges at any depth', function () {
    $merged = ConfigMerge::deep(
        ['a' => ['b' => ['c' => 1, 'd' => 2], 'e' => 3]],
        ['a' => ['b' => ['c' => 10]]],
    );

    expect($merged)->toBe(['a' => ['b' => ['c' => 10, 'd' => 2], 'e' => 3]]);
});

test('keys only the application has are kept', function () {
    expect(ConfigMerge::deep(['a' => 1], ['b' => 2]))->toBe(['a' => 1, 'b' => 2]);
});
