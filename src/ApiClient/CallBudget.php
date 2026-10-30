<?php

namespace UBO\Common\ApiClient;

use Illuminate\Support\Facades\Date;

/**
 * The time one call may take, counted from the moment its verb is invoked. Read from
 * Laravel's Date, so tests move it with travel() or Sleep::syncWithCarbon().
 *
 * @internal
 */
final class CallBudget
{
    /** Set when a retry was skipped because it no longer fit. */
    public bool $exceeded = false;

    private readonly float $startedAt;

    public function __construct(private readonly float $seconds)
    {
        $this->startedAt = self::now();
    }

    /** Seconds left, never below zero. */
    public function remaining(): float
    {
        return max(0.0, $this->seconds - (self::now() - $this->startedAt));
    }

    private static function now(): float
    {
        return Date::now()->getPreciseTimestamp(3) / 1000;
    }
}
