<?php

namespace UBO\Common\Tracing;

/**
 * Typed view of config/ubo-tracing.php, so the module never reads raw config keys.
 */
final readonly class TracingSettings
{
    public function __construct(
        public ?string $responseHeader,
        public bool $trustIncoming,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(array $config): self
    {
        return new self(
            responseHeader: filled($config['response_header'] ?? null) ? (string) $config['response_header'] : null,
            trustIncoming: (bool) ($config['trust_incoming'] ?? true),
        );
    }
}
