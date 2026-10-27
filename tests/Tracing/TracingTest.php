<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use UBO\Common\ApiClient\UboApi;
use UBO\Common\Auth\Contracts\PublicKeyProvider;
use UBO\Common\Tests\TestCase;
use UBO\Common\Tracing\Contracts\TraceContext;

const CALLER_TRACE = '4bf92f3577b34da6a3ce929d0e0e4736';
const CALLER_SPAN = '00f067aa0ba902b7';
const CALLER_TRACEPARENT = '00-'.CALLER_TRACE.'-'.CALLER_SPAN.'-01';

beforeEach(function () {
    Route::get('/trace', fn () => [
        'trace_id' => Context::get('trace_id'),
        'span_id' => Context::get('span_id'),
        'parent_span_id' => Context::get('parent_span_id'),
    ]);
});

test('a request without traceparent starts a new trace and returns its id', function () {
    $response = $this->getJson('/trace')->assertOk();

    expect($response->json('trace_id'))->toMatch('/^[0-9a-f]{32}$/')
        ->and($response->json('span_id'))->toMatch('/^[0-9a-f]{16}$/')
        ->and($response->json('parent_span_id'))->toBeNull()
        ->and($response->headers->get('X-Trace-Id'))->toBe($response->json('trace_id'));
});

test('a valid traceparent is continued in a new span', function () {
    $response = $this->getJson('/trace', ['traceparent' => CALLER_TRACEPARENT])->assertOk();

    expect($response->json('trace_id'))->toBe(CALLER_TRACE)
        ->and($response->json('parent_span_id'))->toBe(CALLER_SPAN)
        ->and($response->json('span_id'))->not->toBe(CALLER_SPAN)
        ->and($response->headers->get('X-Trace-Id'))->toBe(CALLER_TRACE);
});

test('an invalid traceparent starts a new trace', function () {
    $response = $this->getJson('/trace', ['traceparent' => 'not-a-trace'])->assertOk();

    expect($response->json('trace_id'))->toMatch('/^[0-9a-f]{32}$/')
        ->and($response->json('parent_span_id'))->toBeNull();
});

test('the caller trace can be ignored', function () {
    config(['ubo-tracing.trust_incoming' => false]);

    $response = $this->getJson('/trace', ['traceparent' => CALLER_TRACEPARENT])->assertOk();

    expect($response->json('trace_id'))->not->toBe(CALLER_TRACE)
        ->and($response->json('parent_span_id'))->toBeNull();
});

test('the response header can be renamed or turned off', function () {
    config(['ubo-tracing.response_header' => 'X-Request-Id']);
    $this->getJson('/trace')->assertHeader('X-Request-Id')->assertHeaderMissing('X-Trace-Id');

    config(['ubo-tracing.response_header' => '']);
    $this->getJson('/trace')->assertHeaderMissing('X-Request-Id')->assertHeaderMissing('X-Trace-Id');
});

test('error responses also carry the trace id', function () {
    Route::get('/boom', fn () => throw new RuntimeException('boom'));

    $this->getJson('/boom', ['traceparent' => CALLER_TRACEPARENT])
        ->assertServerError()
        ->assertHeader('X-Trace-Id', CALLER_TRACE);
});

test('calls to other APIs continue the trace with this request as parent', function () {
    Http::fake([...fakeTokenEndpoint(), 'https://maestros.test/*' => Http::response(['data' => []])]);

    Route::get('/calls-maestros', function () {
        UboApi::call('api-maestros', 'https://maestros.test')->get('/api/maestros/v1/geografia/paises');
        UboApi::callAsync('api-maestros', 'https://maestros.test')->get('/api/maestros/v1/sistemas')->wait();

        return ['span_id' => Context::get('span_id')];
    });

    $span = $this->getJson('/calls-maestros', ['traceparent' => CALLER_TRACEPARENT])->assertOk()->json('span_id');
    $expected = '00-'.CALLER_TRACE."-{$span}-01";

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/geografia/paises') && $r->header('traceparent') === [$expected]);
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/sistemas') && $r->header('traceparent') === [$expected]);
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/auth/oauth/token') && $r->header('traceparent') === [$expected]);
});

test('downloading the API-Auth public key joins the trace', function () {
    Http::fake(['https://api-auth.test/api/auth/v1/public-key' => Http::response("-----BEGIN PUBLIC KEY-----\nabc\n-----END PUBLIC KEY-----")]);

    app(TraceContext::class)->begin(CALLER_TRACEPARENT);
    app(PublicKeyProvider::class)->get();

    Http::assertSent(fn (Request $r) => str_starts_with($r->header('traceparent')[0] ?? '', '00-'.CALLER_TRACE.'-'));
});

test('outside a request the trace starts on the first call', function () {
    Http::fake([...fakeTokenEndpoint(), 'https://maestros.test/*' => Http::response(['data' => []])]);

    UboApi::call('api-maestros', 'https://maestros.test')->get('/api/maestros/v1/geografia/paises');

    $trace = app(TraceContext::class);

    expect(Context::get('trace_id'))->toBe($trace->traceId());
    Http::assertSent(fn (Request $r) => $r->header('traceparent') === ["00-{$trace->traceId()}-{$trace->spanId()}-01"]);
});

test('a queued job continues the trace in its own span', function () {
    app(TraceContext::class)->begin(CALLER_TRACEPARENT);
    $dispatcherSpan = Context::get('span_id');

    // What the queue does: the job carries the dispatcher's context and restores it.
    $payload = Context::dehydrate();
    Context::flush();
    Context::hydrate($payload);

    expect(Context::get('trace_id'))->toBe(CALLER_TRACE)
        ->and(Context::get('parent_span_id'))->toBe($dispatcherSpan)
        ->and(Context::get('span_id'))->not->toBe($dispatcherSpan);
});

test('with the module disabled nothing is traced and calls still work', function () {
    TestCase::$bootConfig = ['ubo-common.modules.tracing' => false];
    $this->refreshApplication();

    Route::get('/trace', fn () => ['trace_id' => Context::get('trace_id')]);
    Http::fake([...fakeTokenEndpoint(), 'https://maestros.test/*' => Http::response(['data' => []])]);

    $this->getJson('/trace', ['traceparent' => CALLER_TRACEPARENT])
        ->assertExactJson(['trace_id' => null])
        ->assertHeaderMissing('X-Trace-Id');

    UboApi::call('api-maestros', 'https://maestros.test')->get('/api/maestros/v1/geografia/paises');

    expect(app()->bound(TraceContext::class))->toBeFalse();
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/geografia/paises') && ! $r->hasHeader('traceparent'));
});
