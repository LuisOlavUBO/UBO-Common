# Changelog

Formato basado en [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/) y
[versionado semántico](https://semver.org/lang/es/):

- **MAJOR**: rompe compatibilidad (las aplicaciones tienen que cambiar su código o configuración).
- **MINOR**: agrega funcionalidad compatible (un módulo nuevo, una opción nueva).
- **PATCH**: corrige errores sin cambiar el comportamiento esperado.

## [Unreleased]

Versión MAJOR (2.0.0): cambia la forma de llamar a las APIs. Requiere la versión de API-Auth que
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
