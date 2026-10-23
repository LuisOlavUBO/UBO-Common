<?php

namespace UBO\Common\SqlServer;

use InvalidArgumentException;

/**
 * Typed view of config/ubo-sqlserver.php, so the module never reads raw config keys.
 */
final readonly class SqlServerSettings
{
    /** Values SQL Server accepts in SET DATEFORMAT. */
    private const DATE_FORMATS = ['mdy', 'dmy', 'ymd', 'ydm', 'myd', 'dym'];

    public function __construct(
        public string $dateFormat,
    ) {
        if (! in_array($dateFormat, self::DATE_FORMATS, true)) {
            throw new InvalidArgumentException("Unknown SQL Server date format [{$dateFormat}]. Use one of: ".implode(', ', self::DATE_FORMATS).'.');
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(array $config): self
    {
        return new self(
            dateFormat: strtolower(trim((string) ($config['date_format'] ?? 'ymd'))),
        );
    }
}
