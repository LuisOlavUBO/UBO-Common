<?php

namespace UBO\Common\Auth\Testing;

/**
 * Recognises the public key TokenFactory signs its tokens for. It ships with the package and
 * is public, so a production application must never trust it.
 *
 * Keys are compared by a fingerprint of their base64 body, so line endings, literal "\n"
 * (as in a .env), surrounding spaces or the PEM armor lines do not hide the test key.
 *
 * @internal
 */
final class TestKey
{
    public const PUBLIC_KEY_PATH = __DIR__.'/keys/test-public.key';

    /** @var array<string, bool> PEM => whether it is the test key, per process */
    private static array $checked = [];

    public static function isTestKey(string $pem): bool
    {
        return self::$checked[$pem] ??= hash_equals(
            self::fingerprint((string) file_get_contents(self::PUBLIC_KEY_PATH)),
            self::fingerprint($pem),
        );
    }

    public static function fingerprint(string $pem): string
    {
        $body = preg_replace('/-----(BEGIN|END)[^-]*-----/', '', str_replace('\n', '', $pem));

        return hash('sha256', (string) preg_replace('/\s+/', '', (string) $body));
    }
}
