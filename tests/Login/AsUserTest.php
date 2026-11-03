<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use UBO\Common\ApiClient\ApiClientManager;
use UBO\Common\ApiClient\Exceptions\UboApiException;
use UBO\Common\ApiClient\UboApi;
use UBO\Common\Login\Infrastructure\TokenVault;
use UBO\Common\Login\Testing\UboLogin;

const MAESTROS = 'https://maestros.test';

beforeEach(function () {
    // A page of the monolith that shows data of api-maestros, asked as the person or as the system.
    Route::middleware('web')->get('/test-paises', function () {
        $client = UboApi::call('api-maestros', MAESTROS);

        try {
            $response = (request('as') === 'system' ? $client : $client->asUser())->get('/api/maestros/v1/geografia/paises');
        } catch (UboApiException $e) {
            return ['error' => $e->status];
        }

        return ['data' => $response->json('data')];
    });

    Route::middleware('web')->get('/test-paises-async', fn () => [
        'data' => UboApi::callAsync('api-maestros', MAESTROS)->asUser()->get('/api/maestros/v1/geografia/paises')->wait()->json('data'),
    ]);
});

function paises(string $query = ''): TestResponse
{
    return test()->withBrowserSession()->getJson('/test-paises'.$query);
}

function sentToMaestros(): array
{
    return Http::recorded(fn (Request $request) => str_starts_with($request->url(), MAESTROS.'/'))->values()->all();
}

function sentWith(string $token): bool
{
    return collect(sentToMaestros())->contains(fn (array $pair) => $pair[0]->hasHeader('Authorization', 'Bearer '.$token));
}

test('asUser() calls the API with the token of the person and asks API-Auth for nothing', function () {
    loggedIn($token = personToken());
    iamAnswers([MAESTROS.'/*' => Http::response(['data' => 'paises'])]);

    paises()->assertJson(['data' => 'paises']);

    expect(sentWith($token))->toBeTrue();
    Http::assertNotSent(fn (Request $request) => $request->url() === IAM_TOKEN_URL);
});

test('without asUser() the call carries the token of the application, as always', function () {
    loggedIn(personToken());
    iamAnswers([...fakeTokenEndpoint('system-token'), MAESTROS.'/*' => Http::response(['data' => 'paises'])]);

    paises('?as=system')->assertJson(['data' => 'paises']);

    expect(sentWith('system-token'))->toBeTrue();
});

test('asUser() without a login fails before sending anything', function () {
    iamAnswers([MAESTROS.'/*' => Http::response(['data' => 'paises'])]);

    $this->getJson('/test-paises')->assertUnauthorized();
    $this->get('/test-paises')->assertRedirect(route('ubo.login'));

    Http::assertNothingSent();
});

test('asUser() from a queued job or a command fails before sending anything', function () {
    loggedIn(personToken());
    iamAnswers([MAESTROS.'/*' => Http::response(['data' => 'paises'])]);
    app()->instance('request', Illuminate\Http\Request::create('/'));

    expect(fn () => UboApi::call('api-maestros', MAESTROS)->asUser()->get('/api/maestros/v1/geografia/paises'))
        ->toThrow(AuthenticationException::class);

    Http::assertNothingSent();
});

test('a 401 for a token another request just renewed is retried with the new one, without asking IAM', function () {
    $handle = loggedIn($old = personToken());
    $renewed = personToken();
    $calls = 0;
    iamAnswers([MAESTROS.'/*' => function () use ($handle, $renewed, &$calls) {
        if (++$calls === 1) {
            // Another request renewed while this one was in flight: IAM revoked the old token.
            app(TokenVault::class)->put($handle, ['access_token' => $renewed, 'refresh_token' => 'refresh-2'] + app(TokenVault::class)->get($handle));

            return Http::response([], 401);
        }

        return Http::response(['data' => 'paises']);
    }]);

    paises()->assertJson(['data' => 'paises']);

    expect(sentWith($old))->toBeTrue()
        ->and(sentWith($renewed))->toBeTrue();
    Http::assertNotSent(fn (Request $request) => $request->url() === IAM_TOKEN_URL);
});

test('a 401 for the token in force renews it once and retries', function () {
    $handle = loggedIn(personToken());
    $renewed = personToken();
    iamAnswers([
        IAM_TOKEN_URL => Http::response(['token_type' => 'Bearer', 'expires_in' => 3600, 'access_token' => $renewed, 'refresh_token' => 'refresh-2']),
        MAESTROS.'/*' => Http::sequence()->push([], 401)->push(['data' => 'paises']),
    ]);

    paises()->assertJson(['data' => 'paises']);

    expect(sentWith($renewed))->toBeTrue()
        ->and(vaultEntry($handle)['access_token'])->toBe($renewed);
});

test('a 401 with no other token ends the call with that 401, sent once', function () {
    loggedIn(personToken(), refreshToken: null);
    iamAnswers([MAESTROS.'/*' => Http::response([], 401)]);

    paises()->assertJson(['error' => 401]);

    expect(sentToMaestros())->toHaveCount(1);
});

test('a second 401 with the renewed token ends the call', function () {
    loggedIn(personToken());
    iamAnswers([
        IAM_TOKEN_URL => Http::response(['token_type' => 'Bearer', 'expires_in' => 3600, 'access_token' => personToken(), 'refresh_token' => 'refresh-2']),
        MAESTROS.'/*' => Http::response([], 401),
    ]);

    paises()->assertJson(['error' => 401]);

    expect(sentToMaestros())->toHaveCount(2);
});

test('an async call carries the token of the person when the promise is created', function () {
    loggedIn($token = personToken());
    iamAnswers([MAESTROS.'/*' => Http::response(['data' => 'paises'])]);

    $this->withBrowserSession()->getJson('/test-paises-async')->assertJson(['data' => 'paises']);

    expect(sentWith($token))->toBeTrue();
});

test('the circuit of the API, the deadline and the trace apply as without asUser()', function () {
    loggedIn(personToken());
    iamAnswers([MAESTROS.'/*' => Http::response(['data' => 'paises'])]);

    paises()->assertJson(['data' => 'paises']);
    expect(sentToMaestros()[0][0]->hasHeader('traceparent'))->toBeTrue();

    $manager = app(ApiClientManager::class);
    $circuit = $manager->circuit($manager->definition('api-maestros', MAESTROS));

    for ($i = 0; $i < 5; $i++) {
        $circuit->recordFailure();
    }

    paises()->assertJson(['error' => null]);
    expect(sentToMaestros())->toHaveCount(1);
});

test('without a login the answer is to log in, even with the circuit of the API open', function () {
    $manager = app(ApiClientManager::class);
    $circuit = $manager->circuit($manager->definition('api-maestros', MAESTROS));

    for ($i = 0; $i < 5; $i++) {
        $circuit->recordFailure();
    }

    $this->getJson('/test-paises')->assertUnauthorized();
});

test('a person simulated with UboLogin::actingAs() calls a faked API with their token', function () {
    UboLogin::actingAs(id: '42', scopes: ['geografia:leer']);
    UboApi::fake(['api-maestros/*' => ['data' => []]]);

    expect(UboApi::call('api-maestros', null)->asUser()->get('/api/maestros/v1/geografia/paises')->json('data'))->toBe([]);

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'api-maestros.ubo.test')
        && $request->hasHeader('Authorization', 'Bearer test-access-token'));
});
