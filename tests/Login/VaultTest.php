<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use UBO\Common\Login\Infrastructure\TokenVault;

/**
 * Everything the cache holds under the vault's keys, as stored.
 *
 * @return list<string>
 */
function storedVaultValues(string $store = 'array'): array
{
    $values = [];

    foreach ((fn () => $this->storage)->call(Cache::store($store)->getStore()) as $key => $item) {
        if (str_contains($key, 'ubo-login:vault:')) {
            $values[] = (string) $item['value'];
        }
    }

    return $values;
}

test('the session keeps a handle and no token', function () {
    $access = personToken();
    $handle = loggedIn($access);

    $stored = json_encode(app('session.store')->all());

    expect($handle)->toBeString()->toHaveLength(40)
        ->and($stored)->not->toContain($access)
        ->and($stored)->not->toContain('refresh-token')
        ->and(session('ubo_login'))->toBe($handle)
        ->and(vaultEntry()['access_token'])->toBe($access)
        ->and(vaultEntry()['refresh_token'])->toBe('refresh-token');
});

test('the store does not hold the tokens in clear', function () {
    $access = personToken();
    $handle = loggedIn($access);

    $values = storedVaultValues();

    expect($values)->toHaveCount(1)
        ->and($values[0])->not->toContain($access)
        ->and($values[0])->not->toContain('refresh-token')
        ->and($values[0])->not->toContain($handle)
        ->and(json_decode(Crypt::decryptString($values[0]), true)['access_token'])->toBe($access);
});

test('a vault entry written with another key is lost, not an error', function () {
    $handle = loggedIn();
    config(['app.key' => 'base64:'.base64_encode(str_repeat('z', 32))]);
    app()->forgetInstance('encrypter');
    app()->forgetInstance(TokenVault::class);

    expect(app(TokenVault::class)->get($handle))->toBeNull()
        ->and(storedVaultValues())->toBe([]);
});

test('each login gets a new handle and the previous one stops working', function () {
    $first = loggedIn();
    $second = loggedIn();

    expect($second)->not->toBe($first)
        ->and(vaultEntry($first))->toBeNull()
        ->and(vaultEntry($second))->not->toBeNull();

    app('session.store')->put('ubo_login', $first);

    expect(Auth::guard('ubo')->check())->toBeFalse();
});

test('the callback hands nothing of the vault to the browser', function () {
    [$state] = startedLogin();
    $access = personToken();
    iamIssues($access);

    $response = $this->withBrowserSession()->get('/iam/callback?code=the-code&state='.$state)->assertRedirect('/');
    $handle = session('ubo_login');

    $sent = json_encode([$response->headers->all(), $response->getContent(), array_map(fn ($cookie) => $cookie->getValue(), $response->headers->getCookies())]);

    expect($sent)->not->toContain($access)
        ->and($sent)->not->toContain('refresh-token')
        ->and($sent)->not->toContain($handle);
});

test('an entry is forgotten after the time to live without a renewal', function () {
    config(['ubo-login.refresh_ttl' => 600]);
    $handle = loggedIn();

    $this->travel(11)->minutes();

    expect(vaultEntry($handle))->toBeNull();
});

test('a handle that is not one opens nothing', function (string $handle) {
    expect(app(TokenVault::class)->get($handle))->toBeNull();
})->with(['', 'short', str_repeat('x', 41)]);
