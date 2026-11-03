<?php

namespace UBO\Common\Login\Infrastructure;

use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Support\Str;
use JsonException;

/**
 * The tokens of every logged-in person, in the cache (UBO_LOGIN_STORE) and encrypted, each
 * under a random handle that is all the Laravel session keeps. A refresh token works once and
 * renewing revokes the previous access token, so there is a single copy, written under a lock.
 *
 * @internal
 *
 * @phpstan-type Entry array{id: string, uuid: string|null, client_id: string, roles: list<string>, scopes: list<string>, audiences: list<string>, access_token: string, refresh_token: string|null, expires_at: int, started_at: int, checked_at: int, simulated: bool}
 */
final class TokenVault
{
    private const PREFIX = 'ubo-login:vault:';

    private const HANDLE_LENGTH = 40;

    /** Seconds the lock of an entry is held at most, and seconds a request waits for it. */
    private const LOCK_TTL = 10;

    private const LOCK_WAIT = 5;

    public function __construct(
        private readonly Repository $cache,
        private readonly Encrypter $encrypter,
        private readonly int $ttl,
    ) {}

    /**
     * Keep a new entry under a handle nobody had before.
     *
     * @param  Entry  $entry
     * @return string the handle
     */
    public function create(array $entry): string
    {
        $handle = Str::random(self::HANDLE_LENGTH);

        $this->put($handle, $entry);

        return $handle;
    }

    /**
     * Replace an entry and renew its time to live.
     *
     * @param  Entry  $entry
     */
    public function put(string $handle, array $entry): void
    {
        $this->cache->put($this->key($handle), $this->encrypter->encryptString(json_encode($entry, JSON_THROW_ON_ERROR)), $this->ttl);
    }

    /**
     * @return Entry|null null when the handle is unknown, the entry expired or it cannot be read
     */
    public function get(string $handle): ?array
    {
        if (strlen($handle) !== self::HANDLE_LENGTH) {
            return null;
        }

        $payload = $this->cache->get($this->key($handle));

        if (! is_string($payload)) {
            return null;
        }

        try {
            $entry = json_decode($this->encrypter->decryptString($payload), true, 512, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            // Written with another APP_KEY: it will never be readable.
            $this->forget($handle);

            return null;
        }

        return $this->entry($entry);
    }

    public function forget(string $handle): void
    {
        $this->cache->forget($this->key($handle));
    }

    /**
     * Change an entry that may be renewed meanwhile by another request, without losing that.
     *
     * @param  Closure(Entry): Entry  $change
     */
    public function update(string $handle, Closure $change): void
    {
        $this->locked($handle, function () use ($handle, $change) {
            if (($entry = $this->get($handle)) !== null) {
                $this->put($handle, $change($entry));
            }
        });
    }

    /**
     * Run something with the entry's lock, which keeps requests from renewing at once. A store
     * without locks, or a wait that runs out, runs it anyway: the caller must cope with that.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function locked(string $handle, Closure $callback): mixed
    {
        if (! $this->cache->getStore() instanceof LockProvider) {
            return $callback();
        }

        try {
            return $this->cache->lock($this->lockName($handle), self::LOCK_TTL)->block(self::LOCK_WAIT, $callback);
        } catch (LockTimeoutException) {
            return $callback();
        }
    }

    public function lockName(string $handle): string
    {
        return $this->key($handle).':lock';
    }

    /**
     * @return Entry|null
     */
    private function entry(mixed $entry): ?array
    {
        if (! is_array($entry)
            || ! is_string($entry['id'] ?? null)
            || ! is_string($entry['client_id'] ?? null)
            || ! is_string($entry['access_token'] ?? null)
            || ! is_int($entry['expires_at'] ?? null)
            || ! is_int($entry['started_at'] ?? null)
            || ! is_int($entry['checked_at'] ?? null)) {
            return null;
        }

        return [
            'id' => $entry['id'],
            // Entries from before the uuid have none: the guard renews them to get it.
            'uuid' => is_string($entry['uuid'] ?? null) && $entry['uuid'] !== '' ? $entry['uuid'] : null,
            'client_id' => $entry['client_id'],
            'roles' => $this->strings($entry['roles'] ?? null),
            'scopes' => $this->strings($entry['scopes'] ?? null),
            'audiences' => $this->strings($entry['audiences'] ?? null),
            'access_token' => $entry['access_token'],
            'refresh_token' => is_string($entry['refresh_token'] ?? null) && $entry['refresh_token'] !== '' ? $entry['refresh_token'] : null,
            'expires_at' => $entry['expires_at'],
            'started_at' => $entry['started_at'],
            'checked_at' => $entry['checked_at'],
            'simulated' => ($entry['simulated'] ?? false) === true,
        ];
    }

    /**
     * @return list<string>
     */
    private function strings(mixed $values): array
    {
        return is_array($values) ? array_values(array_filter($values, 'is_string')) : [];
    }

    /** The handle is a secret of the session, so it is not the key itself. */
    private function key(string $handle): string
    {
        return self::PREFIX.hash('sha256', $handle);
    }
}
