<?php

namespace UBO\Common\Health;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\ConnectionResolverInterface;
use Throwable;

/**
 * Checks that the database answers a trivial query. Not final, so application tests
 * can mock it to simulate a database that is down.
 */
class DatabaseHealth
{
    public function __construct(
        private readonly ConnectionResolverInterface $db,
        private readonly Repository $config,
    ) {}

    public function isAvailable(): bool
    {
        try {
            $this->db->connection($this->config->get('ubo-health.connection') ?: null)->select('SELECT 1 AS ok');

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
