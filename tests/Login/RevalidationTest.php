<?php

use Illuminate\Support\Facades\Http;
use UBO\Common\Auth\AuthServiceProvider;
use UBO\Common\Auth\Contracts\TokenValidator;
use UBO\Common\Auth\Domain\VerifiedToken;
use UBO\Common\Auth\Exceptions\AuthServerUnavailable;
use UBO\Common\Auth\Infrastructure\Validators\JwtValidator;
use UBO\Common\Auth\Testing\FakeIntrospectionValidator;

beforeEach(function () {
    defineWhoami();
});

/**
 * Counts the questions the login asks IAM about the token (the introspection of the test
 * factory answers them), and can make IAM not answer.
 */
function watchIntrospection(): object
{
    $watch = new class
    {
        public int $asked = 0;

        public bool $down = false;
    };

    app()->bind(AuthServiceProvider::INTROSPECTION, fn ($app) => new class($watch, new FakeIntrospectionValidator(test()->tokens, $app->make(JwtValidator::class))) implements TokenValidator
    {
        public function __construct(private object $watch, private TokenValidator $fake) {}

        public function validate(string $token): VerifiedToken
        {
            $this->watch->asked++;

            return $this->watch->down ? throw new AuthServerUnavailable('API-Auth is down.') : $this->fake->validate($token);
        }
    });

    return $watch;
}

test('a token revoked in another system ends the session at the next check', function () {
    $access = personToken();
    $handle = loggedIn($access);
    $this->tokens->revoke($access);
    $this->travel(5)->minutes();

    whoami()->assertJson(['check' => false]);

    expect(vaultEntry($handle))->toBeNull();
});

test('nothing is asked before the time of the check', function () {
    $access = personToken();
    loggedIn($access);
    $watch = watchIntrospection();
    $this->tokens->revoke($access);
    $this->travel(4)->minutes();

    whoami()->assertJson(['check' => true]);

    expect($watch->asked)->toBe(0);
});

test('a token that is still active is asked about once per period', function () {
    loggedIn();
    $watch = watchIntrospection();
    $this->travel(5)->minutes();

    whoami()->assertJson(['check' => true]);
    whoami()->assertJson(['check' => true]);
    $this->travel(4)->minutes();
    whoami()->assertJson(['check' => true]);

    expect($watch->asked)->toBe(1);

    $this->travel(1)->minutes();
    whoami()->assertJson(['check' => true]);

    expect($watch->asked)->toBe(2);
});

test('the period of the check comes from the configuration', function () {
    config(['ubo-login.revalidate' => 60]);
    $access = personToken();
    loggedIn($access);
    $this->tokens->revoke($access);
    $this->travel(61)->seconds();

    whoami()->assertJson(['check' => false]);
});

test('with the check turned off a revoked token is not noticed', function () {
    config(['ubo-login.revalidate' => 0]);
    $access = personToken();
    loggedIn($access);
    $watch = watchIntrospection();
    $this->tokens->revoke($access);
    $this->travel(30)->minutes();

    whoami()->assertJson(['check' => true]);

    expect($watch->asked)->toBe(0);
});

test('the jwt strategy only knows the signature and the expiry, so nothing is asked', function () {
    config(['ubo-auth.strategy' => 'jwt']);
    $access = personToken();
    loggedIn($access);
    $watch = watchIntrospection();
    $this->tokens->revoke($access);
    $this->travel(10)->minutes();

    whoami()->assertJson(['check' => true]);

    expect($watch->asked)->toBe(0);
});

test('without machine credentials nothing is asked', function () {
    $access = personToken();
    loggedIn($access);
    config(['ubo-auth.client_id' => null, 'ubo-auth.client_secret' => null]);
    $watch = watchIntrospection();
    $this->tokens->revoke($access);
    $this->travel(10)->minutes();

    whoami()->assertJson(['check' => true]);

    expect($watch->asked)->toBe(0);
});

test('the introspection strategy is checked too', function () {
    config(['ubo-auth.strategy' => 'introspection']);
    $access = personToken();
    $handle = loggedIn($access);
    $this->tokens->revoke($access);
    $this->travel(5)->minutes();

    whoami()->assertJson(['check' => false]);

    expect(vaultEntry($handle))->toBeNull();
});

test('with IAM down the person stays and the check is tried again on the next request', function () {
    $access = personToken();
    $handle = loggedIn($access);
    $watch = watchIntrospection();
    $this->travel(5)->minutes();
    $watch->down = true;

    whoami()->assertJson(['check' => true]);
    whoami()->assertJson(['check' => true]);

    // The guard asks again every time it is consulted, and each request consults it more than once.
    expect($watch->asked)->toBeGreaterThanOrEqual(2)
        ->and(vaultEntry($handle))->not->toBeNull();

    $watch->down = false;
    $this->tokens->revoke($access);

    whoami()->assertJson(['check' => false]);
});

test('a renewal counts as a check', function () {
    loggedIn();
    $this->travel(3545)->seconds();
    $watch = watchIntrospection();
    iamRenews(personToken());

    whoami()->assertJson(['check' => true]);

    expect($watch->asked)->toBe(0);
});

test('a token that renewing by another request revoked does not end the session', function () {
    $old = personToken();
    $handle = loggedIn($old);
    $this->travel(5)->minutes();
    $renewed = personToken();
    // Another request renews right after this one read the entry: the old token is revoked by IAM.
    anotherRequestWrites($handle, ['access_token' => $renewed, 'refresh_token' => 'refresh-2'] + vaultEntry($handle));
    $this->tokens->revoke($old);

    whoami()->assertJson(['check' => true]);

    expect(vaultEntry($handle)['access_token'])->toBe($renewed);
});

test('the session ends at the limit however much it is renewed', function () {
    $handle = loggedIn();

    // Twelve renewals of 59 minutes each stay inside the 12 hours.
    foreach (range(1, 12) as $ignored) {
        $this->travel(3545)->seconds();
        iamRenews(personToken());
        whoami()->assertJson(['check' => true]);
    }

    $this->travel(3545)->seconds();
    iamRenews(personToken());

    whoami()->assertJson(['check' => false]);

    expect(vaultEntry($handle))->toBeNull();
    Http::assertNothingSent();
});

test('the limit comes from the configuration', function () {
    config(['ubo-login.max_age' => 600]);
    $handle = loggedIn();
    $this->travel(9)->minutes();

    whoami()->assertJson(['check' => true]);

    $this->travel(1)->minutes();

    whoami()->assertJson(['check' => false]);
    expect(vaultEntry($handle))->toBeNull();
});

test('without a limit the session lasts as long as it is renewed', function () {
    config(['ubo-login.max_age' => 0]);
    loggedIn();

    foreach (range(1, 25) as $ignored) {
        $this->travel(3545)->seconds();
        iamRenews(personToken());
        whoami()->assertJson(['check' => true]);
    }

    expect(vaultEntry()['id'])->toBe('42');
});
