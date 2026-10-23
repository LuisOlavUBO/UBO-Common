<?php

namespace UBO\Common\Tracing\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use UBO\Common\Tracing\Contracts\TraceContext;
use UBO\Common\Tracing\Domain\TraceParent;
use UBO\Common\Tracing\TracingSettings;

/**
 * Global middleware: continues the caller's trace (W3C "traceparent" header) or
 * starts a new one, and returns the trace id to the caller.
 */
final class StartTrace
{
    public function __construct(
        private readonly TraceContext $trace,
        private readonly TracingSettings $settings,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->trace->begin($this->settings->trustIncoming ? $request->headers->get(TraceParent::HEADER) : null);

        $response = $next($request);

        if ($this->settings->responseHeader !== null) {
            $response->headers->set($this->settings->responseHeader, $this->trace->traceId());
        }

        return $response;
    }
}
