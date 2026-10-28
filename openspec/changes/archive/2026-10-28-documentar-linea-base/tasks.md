# Tasks

Este cambio no modifica código: las tareas verifican que cada spec describe el comportamiento real de la
versión 3.0.0.

## 1. Verificación de las specs contra el código

- [x] 1.1 Revisar `specs/module-system` contra `src/UboCommonServiceProvider.php`, `src/Support/` y `config/ubo-common.php`, y verificar que `tests/Support` cubre o contradice cada escenario
- [x] 1.2 Revisar `specs/auth-token-validation` contra `src/Auth/` (middleware, validadores, clave pública, comando) y verificar los escenarios con `tests/Auth/MiddlewareTest.php`, `IntrospectionTest.php`, `PublicKeyTest.php`, `VerifiedTokenTest.php` y `CheckCommandTest.php`
- [x] 1.3 Revisar `specs/auth-service-token` y `specs/auth-testing` contra `src/Auth/Infrastructure/HttpServiceTokenProvider.php` y `src/Auth/Testing/`, y verificar con `tests/Auth/ServiceTokenTest.php`
- [x] 1.4 Revisar `specs/api-client` contra `src/ApiClient/` y verificar con `tests/ApiClient/`
- [x] 1.5 Revisar `specs/problems`, `specs/tracing`, `specs/health` y `specs/sqlserver` contra sus módulos en `src/` y verificar con sus carpetas en `tests/`
- [x] 1.6 Anotar los escenarios sin test que los cubra como candidatos para los cambios de mejora, y verificar que `composer test` sigue pasando sin cambios de código

## 2. Validación del cambio

- [x] 2.1 Ejecutar `openspec validate documentar-linea-base --strict` y verificar que no reporta errores

## Escenarios sin test

Verificados leyendo el código (y, para los reintentos, el cliente HTTP de Laravel), pero sin un test que
los cubra. Son candidatos a tests en los cambios de mejora.

- `module-system`: descubrimiento automático del paquete; módulo sin clave en `ubo-common.modules` (activo); fusión superficial de la config publicada; publicación con la etiqueta `ubo-common-config`.
- `auth-token-validation`: scope comodín `*`; rechazo de `alg` distinto de RS256; claims obligatorios (`jti`, `aud`, `scopes`); tolerancia `UBO_AUTH_LEEWAY`; `UBO_AUTH_PUBLIC_KEY` ilegible; endpoint de clave que responde algo que no es PEM; introspección que responde 5xx; bearer aleatorio con la estrategia `introspection`; timeout de API-Auth; `hybrid` que no llama a la introspección ante un token falsificado; `ubo:auth:check` con `jwt` y sin credenciales.
- `auth-service-token`: API-Auth inalcanzable al pedir el token propio; candado ante peticiones concurrentes; almacén `UBO_AUTH_CACHE_STORE`.
- `api-client`: POST reintentado tras un timeout de lectura (hallazgo 1); esperas de 200 y 400 ms entre reintentos (los tests usan `retry_delay=0`); respuesta 5xx sin JSON.
- `sqlserver`: formato con mayúsculas en `UBO_SQLSERVER_DATE_FORMAT`.

## Workflow follow-up

- Archivar el cambio con `/opsx:archive` para que las specs pasen a `openspec/specs/`.
- Crear los cambios de mejora del diagnóstico como `MODIFIED` sobre los requisitos marcados como "comportamiento conocido".
