# Proposal

## Why

La configuración del módulo Auth falla de forma silenciosa o se queda congelada (hallazgo 4 del
diagnóstico de arquitectura):

- Una variable definida vacía en el `.env` (`UBO_AUTH_TIMEOUT=`) ignora el valor por defecto de `env()` y
  queda en `0`: sin timeout hacia API-Auth, o una descarga de la clave pública en cada petición.
- La configuración publicada se fusiona solo en el primer nivel, así que una app con `ubo-auth.php`
  publicado en una versión anterior pierde las claves nuevas de `cache.*`.
- Los `endpoints` publicados ganan siempre sobre los del paquete, lo que obligó a una migración manual en la 3.0.

## What Changes

- Los valores de configuración de Auth vacíos, nulos o fuera de rango usan su valor por defecto en lugar
  de `0` o `''`.
- La configuración publicada de todos los módulos se fusiona en profundidad con la del paquete: las claves
  anidadas que faltan se completan.
- Las rutas de los endpoints de API-Auth las fija el paquete y solo se pueden reemplazar por variables de
  entorno (`UBO_AUTH_ENDPOINT_TOKEN`, `UBO_AUTH_ENDPOINT_INTROSPECT`, `UBO_AUTH_ENDPOINT_PUBLIC_KEY`). La
  clave `endpoints` de un `ubo-auth.php` publicado se ignora.
- Ninguna validación se hace al registrar los módulos: `composer install` y los builds sin `.env` siguen
  funcionando.
- Cambio de comportamiento deliberado en una minor: ignorar `endpoints` publicados. Ninguna app conocida
  (API-Auth, API-Maestros) tiene `ubo-auth.php` publicado; se documenta en el CHANGELOG.

## Capabilities

### New Capabilities

Ninguna.

### Modified Capabilities

- `module-system`: la fusión de la configuración publicada pasa de superficial a profunda.
- `auth-token-validation`: se agregan los requisitos de valores por defecto ante configuración vacía y de
  rutas fijas de los endpoints de API-Auth.

## Impact

- Código: `ModuleServiceProvider`, `UboCommonServiceProvider`, `AuthSettings`, `config/ubo-auth.php` y el
  fake de `ApiClientManager` (que hoy lee `ubo-auth.endpoints.token`).
- Tests nuevos en `tests/Support/` y `tests/Auth/`.
- Documentación: `docs/modules/auth.md` (variables de endpoints, valores vacíos), `docs/framework.md`
  (regla 5) y `CHANGELOG.md`.
- Versión: 3.1.0 (minor). API-Maestros lo recibe sin cambios de su lado.
