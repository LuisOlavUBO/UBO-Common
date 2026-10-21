# Changelog

Formato basado en [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/) y
[versionado semántico](https://semver.org/lang/es/):

- **MAJOR**: rompe compatibilidad (las aplicaciones tienen que cambiar su código o configuración).
- **MINOR**: agrega funcionalidad compatible (un módulo nuevo, una opción nueva).
- **PATCH**: corrige errores sin cambiar el comportamiento esperado.

## [Unreleased]

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
