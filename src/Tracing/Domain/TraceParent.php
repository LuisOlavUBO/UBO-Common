<?php

namespace UBO\Common\Tracing\Domain;

/**
 * A W3C Trace Context "traceparent" value: version-traceid-parentid-flags.
 *
 *     00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01
 *
 * The trace id is shared by every call in a chain; the parent id identifies the
 * span (the unit of work) that made the call.
 *
 * @see https://www.w3.org/TR/trace-context/
 */
final class TraceParent
{
    public const HEADER = 'traceparent';

    private const VERSION = '00';

    private const SAMPLED = '01';

    private function __construct(
        public readonly string $traceId,
        public readonly string $spanId,
        public readonly string $flags,
    ) {}

    /**
     * A new trace, started here.
     */
    public static function generate(): self
    {
        return new self(self::randomId(16), self::randomId(8), self::SAMPLED);
    }

    /**
     * Parse a header received from a caller, or null when it is not valid (the
     * caller is untrusted, so anything off spec starts a new trace instead).
     */
    public static function parse(?string $header): ?self
    {
        $header = strtolower(trim((string) $header));

        if (! preg_match('/^([0-9a-f]{2})-([0-9a-f]{32})-([0-9a-f]{16})-([0-9a-f]{2})(-.*)?$/', $header, $m)) {
            return null;
        }

        [, $version, $traceId, $spanId, $flags] = $m;
        $rest = $m[5] ?? '';

        // Version ff is forbidden; version 00 has no extra fields. Later versions may
        // append fields, which are ignored.
        if ($version === 'ff' || ($version === self::VERSION && $rest !== '')) {
            return null;
        }

        if ($traceId === str_repeat('0', 32) || $spanId === str_repeat('0', 16)) {
            return null;
        }

        // Only the "sampled" flag is propagated; unknown flags are dropped.
        return new self($traceId, $spanId, (hexdec($flags) & 1) === 1 ? self::SAMPLED : '00');
    }

    /**
     * A span of the same trace, for a new unit of work (e.g. this request).
     */
    public function child(): self
    {
        return new self($this->traceId, self::randomId(8), $this->flags);
    }

    public function toHeader(): string
    {
        return implode('-', [self::VERSION, $this->traceId, $this->spanId, $this->flags]);
    }

    /**
     * Lowercase hex id of the given size in bytes, never all zeros (invalid per spec).
     */
    private static function randomId(int $bytes): string
    {
        do {
            $id = bin2hex(random_bytes($bytes));
        } while (trim($id, '0') === '');

        return $id;
    }
}
