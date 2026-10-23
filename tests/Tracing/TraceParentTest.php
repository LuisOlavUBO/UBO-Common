<?php

use UBO\Common\Tracing\Domain\TraceParent;

const VALID_TRACEPARENT = '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01';

test('a valid header is parsed', function () {
    $parent = TraceParent::parse(VALID_TRACEPARENT);

    expect($parent->traceId)->toBe('4bf92f3577b34da6a3ce929d0e0e4736')
        ->and($parent->spanId)->toBe('00f067aa0ba902b7')
        ->and($parent->flags)->toBe('01')
        ->and($parent->toHeader())->toBe(VALID_TRACEPARENT);
});

test('surrounding spaces and uppercase are tolerated', function () {
    expect(TraceParent::parse(' '.strtoupper(VALID_TRACEPARENT).' ')?->toHeader())->toBe(VALID_TRACEPARENT);
});

test('invalid headers are rejected', function (?string $header) {
    expect(TraceParent::parse($header))->toBeNull();
})->with([
    'missing' => [null],
    'empty' => [''],
    'free text' => ['abc-123'],
    'short trace id' => ['00-4bf92f3577b34da6a3ce929d0e0e47-00f067aa0ba902b7-01'],
    'non hex' => ['00-4bf92f3577b34da6a3ce929d0e0e473z-00f067aa0ba902b7-01'],
    'all-zero trace id' => ['00-00000000000000000000000000000000-00f067aa0ba902b7-01'],
    'all-zero span id' => ['00-4bf92f3577b34da6a3ce929d0e0e4736-0000000000000000-01'],
    'version ff' => ['ff-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01'],
    'version 00 with extra fields' => ['00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01-extra'],
]);

test('a later version with extra fields is read as version 00', function () {
    expect(TraceParent::parse('01-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01-future')?->toHeader())
        ->toBe(VALID_TRACEPARENT);
});

test('only the sampled flag is kept', function (string $flags, string $kept) {
    expect(TraceParent::parse("00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-{$flags}")?->flags)->toBe($kept);
})->with([
    ['00', '00'],
    ['01', '01'],
    ['03', '01'],
    ['fe', '00'],
]);

test('a generated trace is valid and sampled', function () {
    $trace = TraceParent::generate();

    expect(TraceParent::parse($trace->toHeader()))->toEqual($trace)
        ->and($trace->flags)->toBe('01');
});

test('a child span keeps the trace and gets a new span id', function () {
    $parent = TraceParent::parse(VALID_TRACEPARENT);
    $child = $parent->child();

    expect($child->traceId)->toBe($parent->traceId)
        ->and($child->spanId)->not->toBe($parent->spanId)
        ->and($child->flags)->toBe($parent->flags);
});
