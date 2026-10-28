# problems Specification

## Purpose

Da a todas las APIs de la UBO el mismo formato de error, RFC 9457 (`application/problem+json`), con un
código estable en español en el campo `error`, sin exponer detalles internos.

## Requirements
### Requirement: Registro explícito por la aplicación
El módulo SHALL ser pasivo: solo carga su configuración. La aplicación MUST registrar el renderizador en
`bootstrap/app.php` (`$exceptions->render(new ProblemRenderer)`) para que se apliquen las respuestas.

#### Scenario: Módulo activo sin registrar el renderizador
- **WHEN** el módulo está activo pero la aplicación no registró `ProblemRenderer`
- **THEN** las excepciones se presentan con el formato por defecto de Laravel

### Requirement: Renderizador usable con el módulo desactivado
El renderizador SHALL funcionar aunque el módulo `problems` esté desactivado y su configuración no esté
cargada, usando los valores por defecto (rutas `api/*`).

#### Scenario: Módulo desactivado con el renderizador registrado
- **WHEN** `UBO_COMMON_PROBLEMS=false`, la aplicación registró `ProblemRenderer` y se pide una ruta `api/*` inexistente
- **THEN** la respuesta es 404 con `error=RUTA_NO_ENCONTRADA`

### Requirement: Problems construidos por la aplicación
La aplicación SHALL poder construir un `Problem` (status, title, detail, error, extensiones y headers) y
convertirlo en respuesta con la misma forma y `Content-Type` que los que genera el renderizador.

#### Scenario: Error propio de la aplicación
- **WHEN** la aplicación construye `new Problem(400, 'Solicitud OAuth inválida', 'Falta el parámetro.', 'invalid_request', headers: ['Cache-Control' => 'no-store'])` y lo convierte en respuesta
- **THEN** la respuesta es 400, `application/problem+json`, con el header `Cache-Control: no-store` y el cuerpo RFC 9457 con `error=invalid_request`

### Requirement: Rutas atendidas
El renderizador SHALL atender solo las peticiones cuya ruta coincide con algún patrón de
`UBO_PROBLEMS_ROUTES` (sintaxis de `Request::is()`, separados por coma, `api/*` por defecto). Con la lista
vacía o en otras rutas MUST dejar que Laravel presente el error.

#### Scenario: Ruta web
- **WHEN** ocurre un error en `/login` con la configuración por defecto
- **THEN** Laravel presenta su página de error habitual

#### Scenario: Varias rutas
- **WHEN** `UBO_PROBLEMS_ROUTES="api/*,interno/*"` y ocurre un error en `/interno/x`
- **THEN** la respuesta es un problem RFC 9457

### Requirement: Forma de la respuesta
Cada problem SHALL responder con `Content-Type: application/problem+json`, el status HTTP del problem y
el cuerpo `{type: "about:blank", title, status, detail, error, ...extensiones}`, sin escapar caracteres
Unicode.

#### Scenario: Cuerpo de un 401
- **WHEN** una ruta `api/*` lanza un `AuthenticationException`
- **THEN** el cuerpo es `{"type":"about:blank","title":"No autenticado","status":401,"detail":"Se requiere un token de acceso válido, vigente y no revocado.","error":"NO_AUTENTICADO"}`

### Requirement: Mapeo de excepciones a códigos estables
El renderizador SHALL traducir: autenticación → 401 `NO_AUTENTICADO`; autorización con `scopes()` → 403
`SCOPE_INSUFICIENTE` (con los scopes en `detail`); otra autorización → 403 `ACCESO_DENEGADO`; validación →
422 `VALIDACION` con `errores`; límite de peticiones → 429 `LIMITE_EXCEDIDO`; ruta inexistente → 404
`RUTA_NO_ENCONTRADA`; método no permitido → 405 `METODO_NO_PERMITIDO`.

#### Scenario: Scope insuficiente
- **WHEN** el middleware `ubo.auth` lanza `InsufficientScope` con el scope faltante `sistemas:leer`
- **THEN** la respuesta es 403 con `error=SCOPE_INSUFICIENTE` y `detail` `El token no tiene el scope requerido: sistemas:leer.`

#### Scenario: Error de validación
- **WHEN** una ruta `api/*` lanza `ValidationException` con errores en `nombre`
- **THEN** la respuesta es 422 con `error=VALIDACION` y `errores.nombre` con los mensajes

#### Scenario: Autorización envuelta por Laravel
- **WHEN** Laravel envuelve un `AuthorizationException` en un `AccessDeniedHttpException`
- **THEN** se responde según el `AuthorizationException` original

### Requirement: Headers de las excepciones HTTP
Para límite de peticiones, método no permitido y otras excepciones HTTP, la respuesta SHALL conservar los
headers de la excepción (por ejemplo `Retry-After` o `Allow`).

#### Scenario: Límite excedido
- **WHEN** una ruta `api/*` supera su límite de peticiones
- **THEN** la respuesta 429 incluye el header `Retry-After`

### Requirement: API-Auth no disponible
Un `AuthServerUnavailable` SHALL responderse como 503 `AUTENTICACION_NO_DISPONIBLE`, y el motivo interno
MUST registrarse como advertencia en el log sin enviarse al cliente.

#### Scenario: Introspección caída
- **WHEN** la validación del token lanza `AuthServerUnavailable`
- **THEN** la respuesta es 503 con `error=AUTENTICACION_NO_DISPONIBLE` y el motivo queda en el log

### Requirement: Errores de negocio de la aplicación
Una excepción que hereda de `DomainProblem` SHALL responderse con su `status()`, `title()`, su mensaje
como `detail`, su `code()` como `error` y sus `extensions()` como miembros adicionales del cuerpo.

#### Scenario: Error de dominio con extensión
- **WHEN** la aplicación lanza un `DomainProblem` con código `LOTE_DEMASIADO_GRANDE`, status 422 y extensión `maximo=5000`
- **THEN** la respuesta es 422 con `error=LOTE_DEMASIADO_GRANDE` y `maximo=5000` en el cuerpo

### Requirement: Respuestas construidas a propósito
Un `HttpResponseException` SHALL enviarse tal cual, sin convertirlo en problem.

#### Scenario: Respuesta propia
- **WHEN** una ruta `api/*` lanza `HttpResponseException` con su propia respuesta
- **THEN** se envía esa respuesta sin cambios

### Requirement: Sin exposición de detalles internos
Cualquier otra excepción HTTP SHALL responderse con su status y `ERROR_HTTP`, y cualquier excepción no
reconocida con 500 `ERROR_INTERNO`. Los textos MUST ser fijos: nunca incluyen trazas, mensajes de
excepción internos ni rutas de archivos.

#### Scenario: Excepción inesperada
- **WHEN** una ruta `api/*` lanza `RuntimeException('SQLSTATE[...] at /var/www/...')`
- **THEN** la respuesta es 500 con `error=ERROR_INTERNO` y el cuerpo no contiene el mensaje original

### Requirement: Textos del contrato en español
Los códigos `error`, `title` y `detail` SHALL estar en español y fijos en el código, sin depender de
`APP_LOCALE`. Cambiarlos MUST tratarse como un cambio MAJOR.

#### Scenario: Aplicación en otro idioma
- **WHEN** la aplicación tiene `APP_LOCALE=en` y responde un 401
- **THEN** el `title` sigue siendo `No autenticado`
