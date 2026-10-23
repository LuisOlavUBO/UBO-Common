<?php

namespace UBO\Common\Health\Http\Controllers;

use Illuminate\Http\JsonResponse;
use UBO\Common\Health\DatabaseHealth;

/**
 * GET /api/v1/salud, without authentication, for orchestrators and load balancers.
 * The body is part of the UBO APIs' contract (nginx health checks read it).
 */
final class HealthController
{
    public function __invoke(DatabaseHealth $database): JsonResponse
    {
        if (! $database->isAvailable()) {
            return new JsonResponse(['estado' => 'ERROR', 'base_datos' => 'NO_DISPONIBLE'], 503);
        }

        return new JsonResponse(['estado' => 'OK', 'base_datos' => 'OK']);
    }
}
