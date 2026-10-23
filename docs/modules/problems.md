# Módulo Problems

Presenta los errores de una API en el formato **RFC 9457** (`application/problem+json`), con un
**código estable** en el campo `error`. Así todas las APIs de la UBO responden los errores igual, y
quien llama decide qué hacer leyendo `error`, sin depender del texto.

```json
{
  "type": "about:blank",
  "title": "No autenticado",
  "status": 401,
  "detail": "Se requiere un token de acceso válido, vigente y no revocado.",
  "error": "NO_AUTENTICADO"
}
```

El módulo es **pasivo**: no cambia nada hasta que la aplicación registra el renderizador.

## 1. Instalación en la API

En `bootstrap/app.php`:

```php
use UBO\Common\Problems\ProblemRenderer;

->withExceptions(function (Exceptions $exceptions): void {
    $exceptions->render(new ProblemRenderer);

    $exceptions->shouldRenderJsonWhen(
        fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
    );
})
```

Solo responde en las rutas `api/*`. En las demás devuelve `null` y Laravel sigue con su formato
normal. Para cambiar las rutas (separadas por coma, con la sintaxis de `Request::is()`):

```dotenv
UBO_PROBLEMS_ROUTES=api/*,internal/*
```

## 2. Qué responde

| Excepción | Estado | `error` | `title` |
|---|---|---|---|
| `DomainProblem` (errores de negocio, ver abajo) | el suyo | el suyo | el suyo |
| `AuthenticationException` (incluye `InvalidToken`) | 401 | `NO_AUTENTICADO` | No autenticado |
| `AuthorizationException` con `scopes()` (incluye `InsufficientScope`) | 403 | `SCOPE_INSUFICIENTE` | Scope insuficiente |
| Otra `AuthorizationException` (policies, gates) | 403 | `ACCESO_DENEGADO` | Acceso denegado |
| `ValidationException` | 422 | `VALIDACION` | Datos inválidos |
| `ThrottleRequestsException` | 429 | `LIMITE_EXCEDIDO` | Límite de peticiones excedido |
| `NotFoundHttpException` (incluye `ModelNotFoundException`) | 404 | `RUTA_NO_ENCONTRADA` | Ruta no encontrada |
| `MethodNotAllowedHttpException` | 405 | `METODO_NO_PERMITIDO` | Método no permitido |
| `AuthServerUnavailable` (API-Auth no responde) | 503 | `AUTENTICACION_NO_DISPONIBLE` | Autenticación no disponible |
| Otra `HttpException` | la suya | `ERROR_HTTP` | Error en la petición |
| Cualquier otra | 500 | `ERROR_INTERNO` | Error interno |

- El 422 trae los errores por campo en `errores`: `{"errores": {"nombre": ["..."]}}`.
- El 429 y el 405 conservan sus headers (`Retry-After`, `X-RateLimit-*`, `Allow`).
- El 503 deja el motivo en el log (`warning`) y **nunca** en la respuesta.
- El 500 **nunca** expone el mensaje de la excepción, trazas ni rutas de archivos, aunque `APP_DEBUG=true`.
- Una `HttpResponseException` (una respuesta armada a propósito) se envía tal cual.

Los códigos, títulos y detalles son **parte del contrato** de las APIs: están en español y fijos en el
renderizador, no dependen de `APP_LOCALE` ni de archivos de traducción. Cambiar uno es una versión
MAJOR de UBO.Common.

## 3. Errores de negocio

Se heredan de `DomainProblem`. El `detail` es el mensaje de la excepción:

```php
use UBO\Common\Problems\Exceptions\DomainProblem;

class RecursoNoEncontrado extends DomainProblem
{
    public function code(): string { return 'NO_ENCONTRADO'; }
    public function status(): int { return 404; }
    public function title(): string { return 'Recurso no encontrado'; }
}

throw new RecursoNoEncontrado('No existe la región 99.');
```

Para agregar campos al cuerpo, se sobrescribe `extensions()`:

```php
public function extensions(): array
{
    return ['maximo' => 5000];
}
```

## 4. Errores propios de una aplicación

Si una aplicación necesita responder un error que el renderizador no conoce (por ejemplo los errores
OAuth de API-Auth), registra su propio `render()` **antes** de `ProblemRenderer` y arma la respuesta con
`Problem`, para mantener la misma forma:

```php
use UBO\Common\Problems\Domain\Problem;

$exceptions->render(fn (OAuthTokenException $e) => (new Problem(
    $e->status, 'Credenciales inválidas', $e->getMessage(), $e->errorType,
))->toResponse());
$exceptions->render(new ProblemRenderer);
```

## 5. Migrar desde el renderizador propio

| Antes (API-Maestros) | Ahora |
|---|---|
| `App\Shared\Exceptions\RenderizadorProblemas` | `UBO\Common\Problems\ProblemRenderer` |
| `ErrorDominio::codigo()` / `estado()` / `titulo()` / `extras()` | `DomainProblem::code()` / `status()` / `title()` / `extensions()` |

Diferencias con lo que respondía cada API:

- **API-Maestros**: el `detail` del 429 pasa de "…por minuto del cliente." a "Se superó el límite de
  peticiones por minuto.", y una `HttpResponseException` ya no se convierte en problema.
- **API-Auth**: el 503 por `AuthServerUnavailable` pasa de `ERROR_HTTP` a `AUTENTICACION_NO_DISPONIBLE`
  (API-Auth no lanza esa excepción hoy).

## 6. Tests

En los tests de la aplicación basta revisar el código:

```php
$this->getJson('/api/v1/regiones/99')
    ->assertNotFound()
    ->assertHeader('Content-Type', 'application/problem+json')
    ->assertJsonPath('error', 'NO_ENCONTRADO');
```
