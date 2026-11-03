<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use UBO\Common\Login\Contracts\UserTokenProvider;
use UBO\Common\Login\Infrastructure\TokenVault;

beforeEach(function () {
    // Like a controller that checks the person (ubo.login, @can...) and then calls an API as them.
    Route::middleware('web')->get('/test-token', function () {
        Auth::guard('ubo')->check();

        return ['token' => app(UserTokenProvider::class)->token()];
    });
});

function tokenOfThePerson(): TestResponse
{
    return test()->withBrowserSession()->getJson('/test-token');
}

test('a person with a login gets the access token the vault holds', function () {
    loggedIn($token = personToken());

    tokenOfThePerson()->assertJson(['token' => $token]);
});

test('a token due for renewal is renewed before it is handed out', function () {
    loggedIn(personToken());
    $this->travel(3545)->seconds();
    iamRenews($renewed = personToken());

    tokenOfThePerson()->assertJson(['token' => $renewed]);
});

test('without a login there is no token', function () {
    $this->getJson('/test-token')->assertUnauthorized();
});

test('a login whose vault entry is gone has no token', function () {
    $handle = loggedIn(personToken());
    app(TokenVault::class)->forget($handle);

    tokenOfThePerson()->assertUnauthorized();
});

test('a web request without a login is sent to the login of IAM', function () {
    $this->get('/test-token')->assertRedirect(route('ubo.login'));
});

test('IAM left unsettled by the guard is not asked again for the token in the same request', function () {
    $handle = loggedIn($token = personToken());
    $this->travel(3545)->seconds();
    iamAnswers([IAM_TOKEN_URL => connectionRefused()]);

    tokenOfThePerson()->assertJson(['token' => $token]);

    Http::assertSentCount(1);
    expect(vaultEntry($handle)['refresh_token'])->toBe('refresh-token');
});

test('outside a request (a queued job, a command) there is no person', function () {
    loggedIn(personToken());
    // What a queue worker or a command has: a request without a session.
    app()->instance('request', Request::create('/'));

    app(UserTokenProvider::class)->token();
})->throws(AuthenticationException::class);

test('after a rejection the renewed token is handed out, and null when there is no other', function () {
    loggedIn($rejected = personToken());
    iamRenews($renewed = personToken());

    Route::middleware('web')->get('/test-renewed', fn () => [
        'renewed' => app(UserTokenProvider::class)->renewedAfter(request('rejected')),
    ]);

    $this->withBrowserSession()->getJson('/test-renewed?rejected='.$rejected)->assertJson(['renewed' => $renewed]);

    iamAnswers([IAM_TOKEN_URL => connectionRefused()]);

    $this->withBrowserSession()->getJson('/test-renewed?rejected='.$renewed)->assertJson(['renewed' => null]);
});
