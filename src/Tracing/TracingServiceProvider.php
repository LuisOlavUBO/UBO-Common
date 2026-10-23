<?php

namespace UBO\Common\Tracing;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Log\Context\Events\ContextHydrated;
use UBO\Common\Support\ModuleServiceProvider;
use UBO\Common\Tracing\Contracts\TraceContext;
use UBO\Common\Tracing\Http\Middleware\StartTrace;
use UBO\Common\Tracing\Infrastructure\LaravelTraceContext;

/**
 * Tracing module: W3C Trace Context across UBO services. Every request continues the
 * caller's trace or starts one, the ids go to the logs, and the other modules send
 * "traceparent" on their calls (TraceContext).
 */
final class TracingServiceProvider extends ModuleServiceProvider
{
    protected function configName(): string
    {
        return 'ubo-tracing';
    }

    protected function configPath(): string
    {
        return __DIR__.'/config/ubo-tracing.php';
    }

    protected function registerModule(): void
    {
        // Resolved on every use (not singletons) so config changes, e.g. in tests, apply at once.
        $this->app->bind(TracingSettings::class, fn (Application $app) => TracingSettings::fromConfig((array) $app['config']->get('ubo-tracing')));

        $this->app->bind(TraceContext::class, LaravelTraceContext::class);
    }

    protected function bootModule(): void
    {
        // First of all, so everything after it (other middleware, exceptions) is in the trace.
        $kernel = $this->app->make(Kernel::class);

        if (method_exists($kernel, 'prependMiddleware')) {
            $kernel->prependMiddleware(StartTrace::class);
        }

        // Queued jobs restore the dispatcher's context; give each job its own span.
        $this->app['events']->listen(
            ContextHydrated::class,
            fn (ContextHydrated $event) => (new LaravelTraceContext($event->context))->continueInNewSpan(),
        );
    }
}
