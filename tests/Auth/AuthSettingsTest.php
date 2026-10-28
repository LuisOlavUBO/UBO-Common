<?php

use Illuminate\Support\Facades\Http;
use UBO\Common\Auth\AuthSettings;
use UBO\Common\Auth\Contracts\TokenValidator;
use UBO\Common\Auth\Infrastructure\Validators\HybridValidator;
use UBO\Common\Auth\Testing\TokenFactory;

/*
| A config with one value replaced, at "key" or "cache.key".
*/
function authSettingsWith(string $key, mixed $value): AuthSettings
{
    $config = [];
    data_set($config, $key, $value);

    return AuthSettings::fromConfig($config);
}

test('an empty or missing config uses every default', function () {
    $settings = AuthSettings::fromConfig([]);

    expect($settings->strategy)->toBe('hybrid')
        ->and($settings->leeway)->toBe(10)
        ->and($settings->timeout)->toBe(5)
        ->and($settings->cachePrefix)->toBe('ubo-auth:')
        ->and($settings->cacheStore)->toBeNull()
        ->and($settings->publicKeyTtl)->toBe(86400)
        ->and($settings->introspectionTtl)->toBe(30)
        ->and($settings->serviceTokenMargin)->toBe(60)
        ->and($settings->allowUserTokens)->toBeFalse()
        ->and($settings->url)->toBeNull();
});

test('a numeric value that is empty, not a number, zero or negative uses its default', function (string $key, string $property, int $default, mixed $value) {
    expect(authSettingsWith($key, $value)->{$property})->toBe($default);
})->with([
    'timeout' => ['timeout', 'timeout', 5],
    'public key TTL' => ['cache.public_key_ttl', 'publicKeyTtl', 86400],
    'introspection TTL' => ['cache.introspection_ttl', 'introspectionTtl', 30],
    'service token margin' => ['cache.service_token_margin', 'serviceTokenMargin', 60],
])->with([
    'empty' => [''],
    'null' => [null],
    'not a number' => ['abc'],
    'zero' => ['0'],
    'negative' => ['-5'],
]);

test('a valid numeric value is used', function () {
    expect(authSettingsWith('timeout', '7')->timeout)->toBe(7)
        ->and(authSettingsWith('cache.public_key_ttl', 3600)->publicKeyTtl)->toBe(3600);
});

test('the leeway accepts zero but not empty or negative values', function (mixed $value, int $leeway) {
    expect(authSettingsWith('leeway', $value)->leeway)->toBe($leeway);
})->with([
    'zero' => ['0', 0],
    'valid' => ['30', 30],
    'empty' => ['', 10],
    'null' => [null, 10],
    'negative' => ['-1', 10],
]);

test('an empty strategy or cache prefix uses its default', function () {
    expect(authSettingsWith('strategy', '')->strategy)->toBe('hybrid')
        ->and(authSettingsWith('strategy', '  ')->strategy)->toBe('hybrid')
        ->and(authSettingsWith('cache.prefix', '')->cachePrefix)->toBe('ubo-auth:')
        ->and(authSettingsWith('strategy', 'jwt')->strategy)->toBe('jwt');
});

test('a public key TTL of zero still caches the downloaded key', function () {
    config(['ubo-auth.strategy' => 'jwt', 'ubo-auth.cache.public_key_ttl' => 0]);
    Http::fake(['https://api-auth.test/api/auth/v1/public-key' => Http::response(
        file_get_contents(__DIR__.'/../../src/Auth/Testing/keys/test-public.key')
    )]);
    $tokens = new TokenFactory;

    app(TokenValidator::class)->validate($tokens->forClient('SIS', ['geografia:leer']));
    app(TokenValidator::class)->validate($tokens->forClient('SIS', ['geografia:leer']));

    Http::assertSentCount(1);
});

test('an empty strategy resolves the hybrid validator', function () {
    config(['ubo-auth.strategy' => '']);

    expect(app(TokenValidator::class))->toBeInstanceOf(HybridValidator::class);
});

test('an unknown strategy still fails when the validator is resolved', function () {
    config(['ubo-auth.strategy' => 'oauth']);

    app(TokenValidator::class);
})->throws(InvalidArgumentException::class, 'UBO_AUTH_STRATEGY must be jwt, introspection or hybrid.');

test('an empty cache store means the default store', function () {
    expect(authSettingsWith('cache.store', '')->cacheStore)->toBeNull()
        ->and(authSettingsWith('cache.store', 'redis')->cacheStore)->toBe('redis');
});
