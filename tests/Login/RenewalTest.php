<?php

use Illuminate\Contracts\Cache\Store;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use UBO\Common\Auth\Infrastructure\ApiAuthClient;
use UBO\Common\Login\Infrastructure\TokenVault;

beforeEach(function () {
    defineWhoami();
});

/**
 * A person who logged in with a token of an hour, when 55 seconds of it are left: inside the
 * margin of 60 seconds, outside the leeway of 10.
 */
function loginNearExpiry(?string $refreshToken = 'refresh-token'): string
{
    $handle = loggedIn(refreshToken: $refreshToken);

    test()->travel(3545)->seconds();

    return $handle;
}

test('a token about to expire is renewed and the new tokens are kept', function () {
    $handle = loginNearExpiry();
    $renewed = personToken();
    iamRenews($renewed, 'refresh-2');

    whoami()->assertJson(['check' => true, 'id' => '42']);

    $entry = vaultEntry($handle);

    expect($entry['access_token'])->toBe($renewed)
        ->and($entry['refresh_token'])->toBe('refresh-2')
        ->and($entry['expires_at'])->toBe(now()->getTimestamp() + 3600)
        ->and(session('ubo_login'))->toBe($handle);
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request->url() === IAM_TOKEN_URL
        && $request->data() === [
            'grant_type' => 'refresh_token',
            'client_id' => 'login-client',
            'client_secret' => 'login-secret',
            'refresh_token' => 'refresh-token',
        ]);
});

test('the requests that follow a renewal do not call IAM', function () {
    loginNearExpiry();
    iamRenews(personToken());

    whoami()->assertJson(['check' => true]);
    whoami()->assertJson(['check' => true]);
    $this->travel(30)->minutes();
    whoami()->assertJson(['check' => true]);

    Http::assertSentCount(1);
});

test('a token with time left is not renewed', function () {
    loggedIn();
    $this->travel(50)->minutes();
    Http::fake();

    whoami()->assertJson(['check' => true]);

    Http::assertNothingSent();
});

test('the margin of the configuration decides when to renew', function () {
    config(['ubo-login.refresh_margin' => 600]);
    $handle = loggedIn();
    $this->travel(51)->minutes();
    iamRenews($renewed = personToken());

    whoami()->assertJson(['check' => true]);

    expect(vaultEntry($handle)['access_token'])->toBe($renewed);
});

test('the roles, scopes and audiences are those of the renewed token', function () {
    loginNearExpiry();
    iamRenews(personToken(['roles' => ['lector'], 'scopes' => ['geografia:leer', 'otro:leer'], 'audiences' => ['api-maestros', 'api-nueva']]));

    whoami()->assertJson(['check' => true, 'roles' => ['lector'], 'scopes' => ['geografia:leer', 'otro:leer']]);

    expect(vaultEntry()['audiences'])->toBe(['login-client', 'api-maestros', 'api-nueva']);
});

test('the scopes a renewal drops are gone', function () {
    loginNearExpiry();
    iamRenews(personToken(['scopes' => []]));

    whoami()->assertJson(['check' => true, 'roles' => ['coordinador'], 'scopes' => []]);
});

test('requests one after the other renew once', function () {
    loginNearExpiry();
    iamRenews(personToken());

    foreach (range(1, 5) as $ignored) {
        whoami()->assertJson(['check' => true]);
    }

    Http::assertSentCount(1);
});

test('a request that finds the tokens already renewed by another uses them without calling IAM', function () {
    $handle = loginNearExpiry();
    $renewed = personToken();
    anotherRequestWrites($handle, [
        'access_token' => $renewed,
        'refresh_token' => 'refresh-2',
        'expires_at' => now()->getTimestamp() + 3600,
    ] + vaultEntry($handle));
    Http::fake();

    whoami()->assertJson(['check' => true]);

    Http::assertNothingSent();
    expect(vaultEntry($handle)['access_token'])->toBe($renewed);
});

test('a refresh token that another request already used does not end the session', function () {
    $handle = loginNearExpiry();
    $renewed = personToken();
    $entry = [
        'access_token' => $renewed,
        'refresh_token' => 'refresh-2',
        'expires_at' => now()->getTimestamp() + 3600,
    ] + vaultEntry($handle);
    // The lock did not keep them apart: while this request renews, the other's answer lands first.
    iamAnswers([IAM_TOKEN_URL => function () use ($handle, $entry) {
        app(TokenVault::class)->put($handle, $entry);

        return Http::response(['error' => 'invalid_grant'], 400);
    }]);

    whoami()->assertJson(['check' => true, 'id' => '42']);

    expect(vaultEntry($handle))->toMatchArray(['access_token' => $renewed, 'refresh_token' => 'refresh-2']);
});

test('a renewed token of another person ends the session', function () {
    $handle = loginNearExpiry();
    iamRenews(personToken(['id' => '43']));

    whoami()->assertJson(['check' => false]);

    expect(vaultEntry($handle))->toBeNull();
});

test('a renewed token of another client ends the session', function () {
    $handle = loginNearExpiry();
    iamRenews(personToken(['client' => 'another-client']));

    whoami()->assertJson(['check' => false]);

    expect(vaultEntry($handle))->toBeNull();
});

test('a renewed token that is not of a person ends the session', function () {
    $handle = loginNearExpiry();
    iamRenews($this->tokens->forClient('login-client', ['geografia:leer'], roles: ['lector']));

    whoami()->assertJson(['check' => false]);

    expect(vaultEntry($handle))->toBeNull();
});

test('a renewed token signed with another key ends the session', function () {
    $handle = loginNearExpiry();
    iamRenews($this->tokens->forged('login-client', []));

    whoami()->assertJson(['check' => false]);

    expect(vaultEntry($handle))->toBeNull();
});

test('a refresh token IAM rejects ends the session', function () {
    $handle = loginNearExpiry();
    iamRefuses();

    whoami()->assertJson(['check' => false]);

    expect(vaultEntry($handle))->toBeNull();
    Http::assertSentCount(1);
});

test('after the session ends the person does not get back in with the same handle', function () {
    $handle = loginNearExpiry();
    iamRefuses();
    whoami();
    iamRenews(personToken());

    whoami()->assertJson(['check' => false]);

    expect(vaultEntry($handle))->toBeNull();
    Http::assertNothingSent();
});

test('a person who lost all their roles ends the session and does not get in with that token', function () {
    $handle = loginNearExpiry();
    iamRenews(personToken(['roles' => [], 'scopes' => []]));

    whoami()->assertJson(['check' => false]);

    expect(vaultEntry($handle))->toBeNull();
    whoami()->assertJson(['check' => false]);
});

test('without a refresh token the session lasts until the access token expires', function () {
    $handle = loginNearExpiry(refreshToken: null);
    Http::fake();

    whoami()->assertJson(['check' => true]);
    expect(vaultEntry($handle))->not->toBeNull();

    $this->travel(46)->seconds();

    whoami()->assertJson(['check' => false]);
    expect(vaultEntry($handle))->toBeNull();
    Http::assertNothingSent();
});

test('with IAM down a token in force keeps the person logged in', function () {
    $handle = loginNearExpiry();
    iamAnswers([IAM_TOKEN_URL => connectionRefused()]);

    whoami()->assertJson(['check' => true, 'id' => '42']);

    expect(vaultEntry($handle)['refresh_token'])->toBe('refresh-token');
});

test('with IAM down and the token expired the person is out but the tokens stay for the next try', function () {
    $handle = loginNearExpiry();
    $this->travel(46)->seconds();
    iamAnswers([IAM_TOKEN_URL => connectionRefused()]);

    whoami()->assertJson(['check' => false]);

    expect(vaultEntry($handle))->toMatchArray(['refresh_token' => 'refresh-token']);

    iamRenews($renewed = personToken(), 'refresh-2');

    whoami()->assertJson(['check' => true, 'id' => '42']);
    expect(vaultEntry($handle))->toMatchArray(['access_token' => $renewed, 'refresh_token' => 'refresh-2']);
});

test('IAM answering 5xx is not the end of the session', function () {
    $handle = loginNearExpiry();
    iamAnswers([IAM_TOKEN_URL => Http::response('', 503)]);

    whoami()->assertJson(['check' => true]);

    expect(vaultEntry($handle)['refresh_token'])->toBe('refresh-token');
});

test('an open circuit renews nothing and does not end the session', function () {
    $handle = loginNearExpiry();
    iamAnswers([IAM_TOKEN_URL => Http::response([])]);
    for ($i = 0; $i < 5; $i++) {
        app(ApiAuthClient::class)->circuit()->recordFailure();
    }

    whoami()->assertJson(['check' => true]);

    Http::assertNothingSent();
    expect(vaultEntry($handle)['refresh_token'])->toBe('refresh-token');
});

test('the rate limit with the token in force keeps the person logged in and the tokens', function () {
    $handle = loginNearExpiry();
    iamRefuses(429, 'too_many_requests', ['Retry-After' => '30']);
    Log::spy();

    whoami()->assertJson(['check' => true]);

    expect(vaultEntry($handle)['refresh_token'])->toBe('refresh-token');
    Log::shouldHaveReceived('warning')->atLeast()->once();
});

test('the rate limit with the token expired leaves the person out without ending the session', function () {
    $handle = loginNearExpiry();
    $this->travel(46)->seconds();
    iamRefuses(429, 'too_many_requests');

    whoami()->assertJson(['check' => false]);

    expect(vaultEntry($handle)['refresh_token'])->toBe('refresh-token');
});

test('a refusal that is not invalid_grant does not end the session either', function () {
    $handle = loginNearExpiry();
    iamRefuses(401, 'invalid_client');

    whoami()->assertJson(['check' => true]);

    expect(vaultEntry($handle))->not->toBeNull();
});

test('the renewal uses the store and the lock of UBO_LOGIN_STORE', function () {
    config(['cache.stores.login' => ['driver' => 'array'], 'ubo-login.store' => 'login']);
    $handle = loginNearExpiry();
    $lockName = app(TokenVault::class)->lockName($handle);
    $lockedInLoginStore = null;
    $lockedInDefaultStore = null;

    iamAnswers([IAM_TOKEN_URL => function () use ($lockName, &$lockedInLoginStore, &$lockedInDefaultStore) {
        $lockedInLoginStore = ! Cache::store('login')->lock($lockName, 1)->get();
        $lockedInDefaultStore = ! Cache::store('array')->lock($lockName, 1)->get();

        return Http::response(['expires_in' => 3600, 'access_token' => personToken(), 'refresh_token' => 'refresh-2']);
    }]);

    whoami()->assertJson(['check' => true]);

    $key = 'ubo-login:vault:'.hash('sha256', $handle);

    expect($lockedInLoginStore)->toBeTrue()
        ->and($lockedInDefaultStore)->toBeFalse()
        ->and(Cache::store('login')->has($key))->toBeTrue()
        ->and(Cache::store('array')->has($key))->toBeFalse()
        ->and(vaultEntry($handle)['refresh_token'])->toBe('refresh-2');
});

test('the lock is released after the renewal', function () {
    $handle = loginNearExpiry();
    iamRenews(personToken());

    whoami();

    $lock = Cache::lock(app(TokenVault::class)->lockName($handle), 1);

    expect($lock->get())->toBeTrue();
    $lock->release();
});

test('an entry that is no longer in the vault ends the session without an error', function () {
    $handle = loggedIn();
    app(TokenVault::class)->forget($handle);
    Http::fake();

    whoami()->assertOk()->assertJson(['check' => false, 'id' => null]);

    Http::assertNothingSent();
});

test('a vault that cannot lock renews all the same', function () {
    $store = new class implements Store
    {
        /** @var array<string, mixed> */
        public array $items = [];

        public function get($key)
        {
            return $this->items[$key] ?? null;
        }

        public function many(array $keys)
        {
            return array_map(fn ($key) => $this->get($key), array_combine($keys, $keys));
        }

        public function put($key, $value, $seconds)
        {
            $this->items[$key] = $value;

            return true;
        }

        public function putMany(array $values, $seconds)
        {
            foreach ($values as $key => $value) {
                $this->put($key, $value, $seconds);
            }

            return true;
        }

        public function increment($key, $value = 1)
        {
            return $this->items[$key] = ($this->items[$key] ?? 0) + $value;
        }

        public function decrement($key, $value = 1)
        {
            return $this->increment($key, -$value);
        }

        public function forever($key, $value)
        {
            return $this->put($key, $value, 0);
        }

        public function forget($key)
        {
            unset($this->items[$key]);

            return true;
        }

        public function flush()
        {
            $this->items = [];

            return true;
        }

        // Part of the interface since Laravel 13; harmless before.
        public function touch($key, $seconds)
        {
            return isset($this->items[$key]);
        }

        public function getPrefix()
        {
            return '';
        }
    };
    Cache::extend('nolocks', fn () => Cache::repository($store));
    config(['cache.stores.login' => ['driver' => 'nolocks'], 'ubo-login.store' => 'login']);

    $handle = loginNearExpiry();
    iamRenews($renewed = personToken());

    whoami()->assertJson(['check' => true]);

    expect(vaultEntry($handle)['access_token'])->toBe($renewed);
});
