# Proposal

## Why

UBO.Common (`ubo/common` 3.0.0) no tiene specs de OpenSpec: su comportamiento solo está descrito en
`docs/`, en los tests y en el código. Antes de implementar las mejoras del diagnóstico de
arquitectura hace falta una línea base verificable, para que cada mejora se exprese como un cambio
explícito (`MODIFIED`) sobre requisitos existentes y no como una reescritura sin referencia.

## What Changes

- Se documenta como specs el comportamiento **actual** de la versión 3.0.0, sin cambiar código.
- Las specs describen lo que el código hace hoy, incluidos los comportamientos que el diagnóstico
  marcó como defectos (por ejemplo, el reintento de POST/PATCH ante un error de conexión). Cada uno
  lleva una nota "Comportamiento conocido" para que el cambio que lo corrija lo modifique.
- No hay cambios de comportamiento ni cambios **BREAKING**.

## Capabilities

### New Capabilities

- `module-system`: carga de módulos por interruptor, base `ModuleServiceProvider`, fusión y publicación de configuración.
- `auth-token-validation`: middleware `ubo.auth`, estrategias `jwt`/`introspection`/`hybrid`, clave pública y rotación, audiencia, scopes, `VerifiedToken`, errores 401/403/503 y el comando `ubo:auth:check`.
- `auth-service-token`: tokens propios de la aplicación obtenidos de API-Auth (client credentials), caché por API y scopes, candado y renovación.
- `auth-testing`: `TokenFactory` y la introspección simulada para probar rutas protegidas sin API-Auth.
- `api-client`: `UboApi::call`, `callAsync` y `all`, token automático por API, política de reintentos, `UboApiException` y `UboApi::fake()`.
- `problems`: respuestas de error RFC 9457 con código estable en español, mapeo de excepciones y `DomainProblem`.
- `tracing`: W3C Trace Context, middleware `StartTrace`, contexto en logs, propagación a jobs y a llamadas salientes.
- `health`: endpoint de salud con chequeo de base de datos y contrato de respuesta.
- `sqlserver`: `SET DATEFORMAT` en cada conexión `sqlsrv`.

### Modified Capabilities

Ninguna (no existen specs previas).

## Impact

- Solo se agregan archivos en `openspec/`. No se modifica código, configuración ni dependencias.
- Al archivar el cambio, las specs pasan a `openspec/specs/` y se convierten en la referencia para los
  cambios de mejora (resiliencia de ApiClient y Auth, configuración, observabilidad, etc.).
