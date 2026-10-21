<?php

namespace UBO\Common\Auth\Infrastructure;

use DateTimeImmutable;
use Illuminate\Support\Facades\Date;
use Psr\Clock\ClockInterface;

/**
 * PSR-20 clock backed by Laravel's Date, so tests can move time with $this->travel().
 */
final class LaravelClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return Date::now()->toDateTimeImmutable();
    }
}
