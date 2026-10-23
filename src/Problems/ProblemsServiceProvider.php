<?php

namespace UBO\Common\Problems;

use UBO\Common\Support\ModuleServiceProvider;

/**
 * Problems module: RFC 9457 (application/problem+json) error responses with a stable
 * code in "error". Passive: it only loads its config; the application registers
 * ProblemRenderer in bootstrap/app.php.
 */
final class ProblemsServiceProvider extends ModuleServiceProvider
{
    protected function configName(): string
    {
        return 'ubo-problems';
    }

    protected function configPath(): string
    {
        return __DIR__.'/config/ubo-problems.php';
    }
}
