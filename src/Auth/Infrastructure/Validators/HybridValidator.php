<?php

namespace UBO\Common\Auth\Infrastructure\Validators;

use Illuminate\Contracts\Cache\Repository;
use Psr\Log\LoggerInterface;
use UBO\Common\Auth\Contracts\TokenValidator;
use UBO\Common\Auth\Domain\VerifiedToken;
use UBO\Common\Auth\Exceptions\AuthServerUnavailable;

/**
 * Local check first (forged, malformed or expired tokens never reach API-Auth),
 * then a cached introspection so revoked tokens are rejected.
 *
 * In degraded mode, a token that passed the local check is accepted when introspection
 * cannot reach API-Auth: revocations go unnoticed while API-Auth is down.
 */
final class HybridValidator implements TokenValidator
{
    /** Seconds between two degraded-mode warnings, so an outage does not flood the log. */
    private const WARNING_INTERVAL = 60;

    public function __construct(
        private readonly TokenValidator $local,
        private readonly TokenValidator $remote,
        private readonly bool $degradedMode = false,
        private readonly ?LoggerInterface $log = null,
        private readonly ?Repository $cache = null,
        private readonly string $cachePrefix = 'ubo-auth:',
    ) {}

    public function validate(string $token): VerifiedToken
    {
        $verified = $this->local->validate($token);

        try {
            $this->remote->validate($token);
        } catch (AuthServerUnavailable $e) {
            if (! $this->degradedMode) {
                throw $e;
            }

            $this->warn($e);
        }

        return $verified;
    }

    private function warn(AuthServerUnavailable $e): void
    {
        if ($this->cache !== null && ! $this->cache->add($this->cachePrefix.'degraded-warning', true, self::WARNING_INTERVAL)) {
            return;
        }

        $this->log?->warning('UBO.Common degraded mode: tokens are accepted without checking revocation because introspection failed: '.$e->getMessage());
    }
}
