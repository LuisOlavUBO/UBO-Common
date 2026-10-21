<?php

namespace UBO\Common\Auth\Contracts;

/**
 * Supplies the PEM public key API-Auth signs its tokens with.
 */
interface PublicKeyProvider
{
    public function get(): string;

    /**
     * Discard any cached copy and load the key again (API-Auth rotated its keys).
     * Returns null when the key cannot change (e.g. it is fixed in config).
     */
    public function refresh(): ?string;
}
