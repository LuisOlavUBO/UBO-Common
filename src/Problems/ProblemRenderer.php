<?php

namespace UBO\Common\Problems;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;
use UBO\Common\Auth\Exceptions\AuthServerUnavailable;
use UBO\Common\Problems\Domain\Problem;
use UBO\Common\Problems\Exceptions\DomainProblem;

/**
 * Renders every exception of the configured routes (api/* by default) as RFC 9457
 * (application/problem+json) with a stable code in "error". Register it in
 * bootstrap/app.php: $exceptions->render(new ProblemRenderer).
 *
 * Codes, titles and details are part of the UBO APIs' contract, so they are in Spanish
 * and fixed here. Never exposes traces, internal exception messages or file paths.
 */
final class ProblemRenderer
{
    public function __invoke(Throwable $e, Request $request): ?JsonResponse
    {
        // A response built on purpose is sent as is.
        if ($e instanceof HttpResponseException || ! $this->handles($request)) {
            return null;
        }

        return $this->problemFor($e)->toResponse();
    }

    private function handles(Request $request): bool
    {
        $routes = ProblemsSettings::fromConfig((array) config('ubo-problems', []))->routes;

        return $routes !== [] && $request->is(...$routes);
    }

    private function problemFor(Throwable $e): Problem
    {
        // Laravel wraps AuthorizationException in AccessDeniedHttpException before rendering.
        if ($e->getPrevious() instanceof AuthorizationException) {
            $e = $e->getPrevious();
        }

        return match (true) {
            $e instanceof DomainProblem => new Problem($e->status(), $e->title(), $e->getMessage(), $e->code(), $e->extensions()),
            $e instanceof AuthenticationException => new Problem(
                401, 'No autenticado', 'Se requiere un token de acceso válido, vigente y no revocado.', 'NO_AUTENTICADO',
            ),
            $e instanceof AuthorizationException && method_exists($e, 'scopes') => new Problem(
                403, 'Scope insuficiente', 'El token no tiene el scope requerido: '.implode(', ', $e->scopes()).'.', 'SCOPE_INSUFICIENTE',
            ),
            $e instanceof AuthorizationException => new Problem(
                403, 'Acceso denegado', 'No tiene permiso para realizar esta operación.', 'ACCESO_DENEGADO',
            ),
            $e instanceof ValidationException => new Problem(
                422, 'Datos inválidos', 'Los datos enviados no son válidos.', 'VALIDACION', ['errores' => $e->errors()],
            ),
            $e instanceof ThrottleRequestsException => new Problem(
                429, 'Límite de peticiones excedido', 'Se superó el límite de peticiones por minuto.', 'LIMITE_EXCEDIDO',
                headers: $e->getHeaders(),
            ),
            $e instanceof NotFoundHttpException => new Problem(
                404, 'Ruta no encontrada', 'La ruta solicitada no existe.', 'RUTA_NO_ENCONTRADA',
            ),
            $e instanceof MethodNotAllowedHttpException => new Problem(
                405, 'Método no permitido', 'El método HTTP no está permitido para esta ruta.', 'METODO_NO_PERMITIDO',
                headers: $e->getHeaders(),
            ),
            $e instanceof AuthServerUnavailable => $this->authServerUnavailable($e),
            $e instanceof HttpExceptionInterface => new Problem(
                $e->getStatusCode(), 'Error en la petición', 'La petición no pudo procesarse.', 'ERROR_HTTP',
                headers: $e->getHeaders(),
            ),
            default => new Problem(
                500, 'Error interno', 'Ocurrió un error inesperado al procesar la petición.', 'ERROR_INTERNO',
            ),
        };
    }

    /**
     * API-Auth did not answer: the reason goes to the log, never to the caller.
     */
    private function authServerUnavailable(AuthServerUnavailable $e): Problem
    {
        Log::warning('The token could not be checked with API-Auth: '.$e->getMessage());

        return new Problem(
            503, 'Autenticación no disponible', 'No fue posible verificar el token de acceso. Intente nuevamente.', 'AUTENTICACION_NO_DISPONIBLE',
        );
    }
}
