<?php

namespace UBO\Common\Tracing\Contracts;

/**
 * The trace of the current unit of work (an HTTP request, a queued job, a command).
 * Other modules use it to propagate the trace on their outgoing calls.
 */
interface TraceContext
{
    /**
     * Continue the caller's trace from its "traceparent" header, or start a new one
     * when there is none or it is invalid. A new span is created for this work.
     */
    public function begin(?string $traceparent = null): void;

    /**
     * Trace id shared by every call in the chain (32 hex characters).
     */
    public function traceId(): string;

    /**
     * Id of the current span (16 hex characters).
     */
    public function spanId(): string;

    /**
     * Headers to send on a call to another UBO service, so it joins this trace with
     * the current span as its parent.
     *
     * @return array<string, string>
     */
    public function headers(): array;
}
