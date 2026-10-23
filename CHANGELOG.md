# Changelog

Formato basado en [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/) y
[versionado semántico](https://semver.org/lang/es/):

- **MAJOR**: rompe compatibilidad (las aplicaciones tienen que cambiar su código o configuración).
- **MINOR**: agrega funcionalidad compatible (un módulo nuevo, una opción nueva).
- **PATCH**: corrige errores sin cambiar el comportamiento esperado.

## [Unreleased]

## [2.2.0] - 2026-10-23

Versión MINOR: compatible con la 2.1. Agrega el módulo `Tracing`, activado por defecto; las
aplicaciones no tienen que cambiar nada.

### Added

- **Tracing**: módulo nuevo con W3C Trace Context. Un middleware global continúa el `traceparent` de
  quien llama (o empieza una traza nueva si no viene o es inválido), deja `trace_id`, `span_id` y
  `parent_span_id` en el `Context` de Laravel (aparecen en los logs y viajan con los jobs) y devuelve
  el `trace_id` en el header `X-Trace-Id`. Se configura con `UBO_COMMON_TRACING`,
  `UBO_TRACING_RESPONSE_HEADER` y `UBO_TRACING_TRUST_INCOMING`.
- **Tracing**: contrato `TraceContext` (`traceId()`, `spanId()`, `headers()`).
- **ApiClient**: las llamadas de `UboApi::call()` y `callAsync()` envían `traceparent`, con el span de
  la petición actual como padre.
- **Auth**: las llamadas a API-Auth (token, introspección y clave pública) envían `traceparent`.
- Dependencia `illuminate/log`, donde vive `Context`.

## [2.1.1] - 2026-10-22

### Fixed

- **Auth**: `TokenFactory::forUser()` también emite los tokens para la API configurada en
  `ubo-auth.api` (y acepta `api:`). Con `UBO_AUTH_API` definida, un token de sistema simulado con un
  `sub` que solo difiere en mayúsculas del client id era rechazado en los tests por `wrong_audience`.

## [2.1.0] - 2026-10-22

Versión MINOR: compatible con la 2.0. Para rechazar tokens emitidos para otra API hace falta la
versión de API-Auth que pone el nombre de la API en el `aud`.

### Added

- **Auth**: `UBO_AUTH_API` (`ubo-auth.api`), el nombre de esta API en API-Auth. Con ella definida,
  el middleware `ubo.auth` rechaza (401 `wrong_audience`) los tokens de sistema cuyo `aud` no nombra
  a esta API, aunque traigan un scope con el mismo nombre. Sin ella no cambia nada.
- **Auth**: `VerifiedToken::$audiences` y `VerifiedToken::isFor()`. La introspección conserva el
  `aud` que devuelve API-Auth.
- **Auth**: `TokenFactory::forClient()` emite los tokens para la API configurada en `ubo-auth.api`
  y acepta `api:` para simular tokens de otra API.
- **Auth**: `ubo:auth:check` muestra el nombre de la API configurado.

## [2.0.0] - 2026-10-21

Versión MAJOR: cambia la forma de llamar a las APIs. Requiere la versión de API-Auth que
acepta el parámetro `api` en `/oauth/token`.

### Changed

- **ApiClient**: `UboApi::call()` y `UboApi::callAsync()` reciben el nombre de la API en API-Auth y
  su URL: `UboApi::call('api-maestros', config('services.maestros.url'))`. Common ya no tiene un
  catálogo de APIs; cada sistema guarda las URLs que usa en su propia configuración.
- **ApiClient**: cada API recibe un token emitido solo para ella. Common lo pide con `api=<nombre>`
  y API-Auth le pone los scopes que el sistema tiene en esa API; ya no se declaran scopes. Hay un
  token en caché por API.
- **ApiClient**: `UboApi::fake()` usa como clave el nombre de la API en API-Auth
  (`'api-maestros/api/v1/...'`) o una URL completa.
- **Auth**: `ServiceTokenProvider::token()` y `forget()` aceptan el parámetro opcional `$api`.
- **Auth**: cuando API-Auth rechaza un pedido de token, el mensaje incluye su `hint` (por ejemplo,
  qué scope no es válido).

### Removed

- **ApiClient**: el arreglo `apis` de `config/ubo-apis.php`, la variable `UBO_API_MAESTROS_URL` y la
  excepción `UnknownApi`. Una llamada sin nombre de API o sin URL lanza `InvalidArgumentException`.

## [1.0.1] - 2026-10-21

### Fixed

- **Auth**: un token de sistema (client credentials) cuyo `sub` es el client_id con otras
  mayúsculas/minúsculas (SQL Server devuelve los GUID en mayúsculas) ya no se confunde con un token
  de usuario. Afectaba a las estrategias `jwt`, `introspection` y `hybrid`
  (`VerifiedToken::userIdFromSubject()`).

## [1.0.0] - 2026-10-21

### Added

- Estructura modular: `UboCommonServiceProvider`, `Support\ModuleServiceProvider` y `config/ubo-common.php`.
- Módulo **Auth**: middleware `ubo.auth` que valida tokens de API-Auth con las estrategias `jwt`,
  `introspection` y `hybrid`; acepta solo tokens M2M por defecto; incluye `VerifiedToken`,
  `ServiceTokenProvider`, el comando `ubo:auth:check` y `Testing\TokenFactory`.
- Módulo **ApiClient**: `UboApi::call()`, `callAsync()` y `all()`, con token automático, renovación
  ante 401, reintentos, errores como `UboApiException` y `UboApi::fake()` para tests.
