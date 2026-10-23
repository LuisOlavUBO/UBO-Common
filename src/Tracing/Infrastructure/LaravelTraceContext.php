<?php

namespace UBO\Common\Tracing\Infrastructure;

use Illuminate\Log\Context\Repository as Context;
use UBO\Common\Tracing\Contracts\TraceContext;
use UBO\Common\Tracing\Domain\TraceParent;

/**
 * Keeps the trace in Laravel's Context, so trace_id, span_id and parent_span_id are
 * added to every log line and travel with queued jobs.
 */
final class LaravelTraceContext implements TraceContext
{
    public const TRACE_ID = 'trace_id';

    public const SPAN_ID = 'span_id';

    public const PARENT_SPAN_ID = 'parent_span_id';

    /** Hidden: not useful in the logs, only to rebuild the outgoing header. */
    private const FLAGS = 'ubo_trace_flags';

    public function __construct(private readonly Context $context) {}

    public function begin(?string $traceparent = null): void
    {
        $parent = TraceParent::parse($traceparent);
        $span = $parent?->child() ?? TraceParent::generate();

        $this->store($span, $parent?->spanId);
    }

    /**
     * A queued job is new work of the trace that dispatched it: it gets its own span,
     * child of the dispatching one.
     */
    public function continueInNewSpan(): void
    {
        $current = TraceParent::parse($this->header());

        if ($current !== null) {
            $this->store($current->child(), $current->spanId);
        }
    }

    public function traceId(): string
    {
        $this->ensureStarted();

        return $this->context->get(self::TRACE_ID);
    }

    public function spanId(): string
    {
        $this->ensureStarted();

        return $this->context->get(self::SPAN_ID);
    }

    public function headers(): array
    {
        $this->ensureStarted();

        return [TraceParent::HEADER => $this->header()];
    }

    /**
     * Outside a request (a command, a scheduled task) the trace starts on first use.
     */
    private function ensureStarted(): void
    {
        if (! is_string($this->context->get(self::TRACE_ID))) {
            $this->begin();
        }
    }

    private function header(): string
    {
        return implode('-', [
            '00',
            $this->context->get(self::TRACE_ID),
            $this->context->get(self::SPAN_ID),
            $this->context->getHidden(self::FLAGS, '01'),
        ]);
    }

    private function store(TraceParent $span, ?string $parentSpanId): void
    {
        $this->context->add([
            self::TRACE_ID => $span->traceId,
            self::SPAN_ID => $span->spanId,
            self::PARENT_SPAN_ID => $parentSpanId,
        ]);

        $this->context->addHidden(self::FLAGS, $span->flags);
    }
}
