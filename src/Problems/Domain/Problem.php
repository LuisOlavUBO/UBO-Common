<?php

namespace UBO\Common\Problems\Domain;

use Illuminate\Http\JsonResponse;

/**
 * An RFC 9457 problem: {type, title, status, detail, error, ...extensions}, where
 * "error" is the stable code callers branch on. Applications can build one for errors
 * ProblemRenderer does not know, so every response keeps the same shape.
 */
final readonly class Problem
{
    /**
     * @param  array<string, mixed>  $extensions  Extra members of the body (e.g. ['errores' => [...]]).
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public int $status,
        public string $title,
        public string $detail,
        public string $error,
        public array $extensions = [],
        public array $headers = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => 'about:blank',
            'title' => $this->title,
            'status' => $this->status,
            'detail' => $this->detail,
            'error' => $this->error,
            ...$this->extensions,
        ];
    }

    public function toResponse(): JsonResponse
    {
        return new JsonResponse(
            $this->toArray(),
            $this->status,
            [...$this->headers, 'Content-Type' => 'application/problem+json'],
            JSON_UNESCAPED_UNICODE,
        );
    }
}
