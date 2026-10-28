<?php

use UBO\Common\Auth\Testing\TestKey;

beforeEach(function () {
    $this->testKey = file_get_contents(TestKey::PUBLIC_KEY_PATH);
});

test('the test public key is recognised in any format', function (Closure $format) {
    expect(TestKey::isTestKey($format($this->testKey)))->toBeTrue();
})->with([
    'as it ships' => [fn (string $pem) => $pem],
    'with CRLF line endings' => [fn (string $pem) => str_replace("\n", "\r\n", $pem)],
    'with literal \n, as in a .env' => [fn (string $pem) => str_replace("\n", '\n', trim($pem))],
    'with surrounding spaces' => [fn (string $pem) => "  {$pem}   \n\n"],
    'without line breaks' => [fn (string $pem) => str_replace("\n", '', $pem)],
]);

test('another public key is not the test key', function () {
    // The public half of the key TokenFactory::forged() signs with.
    $other = openssl_pkey_get_details(openssl_pkey_get_private(
        file_get_contents(dirname(TestKey::PUBLIC_KEY_PATH).'/unknown-private.key')
    ))['key'];

    expect(TestKey::isTestKey($other))->toBeFalse()
        ->and(TestKey::isTestKey(''))->toBeFalse();
});
