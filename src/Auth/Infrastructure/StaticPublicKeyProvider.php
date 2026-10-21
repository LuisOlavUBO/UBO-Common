<?php

namespace UBO\Common\Auth\Infrastructure;

use RuntimeException;
use UBO\Common\Auth\Contracts\PublicKeyProvider;

/**
 * Public key fixed in configuration (UBO_AUTH_PUBLIC_KEY): PEM text or a file path.
 * No network calls; rotating the key means redeploying the configuration.
 */
final class StaticPublicKeyProvider implements PublicKeyProvider
{
    public function __construct(private readonly string $key) {}

    public function get(): string
    {
        if (str_contains($this->key, '-----BEGIN')) {
            return str_replace('\n', "\n", $this->key);
        }

        if (! is_file($this->key) || ($contents = file_get_contents($this->key)) === false) {
            throw new RuntimeException("UBO_AUTH_PUBLIC_KEY is neither a PEM key nor a readable file: {$this->key}");
        }

        return $contents;
    }

    public function refresh(): ?string
    {
        return null;
    }
}
