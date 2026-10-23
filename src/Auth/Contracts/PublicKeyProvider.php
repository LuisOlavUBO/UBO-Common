<?php

namespace UBO\Common\Auth\Contracts;

/**
 * Supplies the PEM public key API-Auth signs its tokens with.
 */
interface PublicKeyProvider
{
    public function get(): string;

    /**
     * Load the key again, replacing any cached copy (API-Auth rotated its keys).
     * Returns null when no new key is available: it cannot change (e.g. it is fixed
     * in config) or it could not be loaded, in which case the cached copy is kept.
     */
    public function refresh(): ?string;
}
