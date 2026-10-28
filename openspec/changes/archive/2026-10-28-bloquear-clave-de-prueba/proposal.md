# Proposal

## Why

Las claves RSA de prueba de `TokenFactory` se distribuyen dentro del paquete y son públicas. Si un entorno
real confía por error en la clave pública de prueba, ya sea fijada en `UBO_AUTH_PUBLIC_KEY` o porque
API-Auth quedó firmando con ella, cualquiera con acceso al paquete puede forjar tokens válidos con todos
los scopes (hallazgo 6 del diagnóstico de arquitectura).

## What Changes

- En producción (`APP_ENV=production`), si la clave pública que se usaría para validar es la de prueba,
  la validación falla cerrada: ningún token se acepta y la respuesta es 503.
- El motivo queda en el log, nunca en la respuesta.
- `TokenFactory::fake()` falla con un error explícito si se llama en producción.
- `php artisan ubo:auth:check` marca como fallida la revisión de la clave pública en ese caso.
- Fuera de producción no cambia nada: los tests y los entornos locales siguen usando la clave de prueba.
- No hay cambios **BREAKING** para un entorno bien configurado.

## Capabilities

### New Capabilities

Ninguna.

### Modified Capabilities

- `auth-testing`: el requisito "Claves de prueba distribuidas con el paquete" pasa a exigir que la clave de
  prueba se rechace en producción, y `TokenFactory::fake()` deja de poder usarse ahí.
- `auth-token-validation`: el requisito "Comando de diagnóstico" agrega la revisión de que, en producción,
  la clave pública no sea la de prueba.

## Impact

- Código: validación local de firma (`JwtValidator` o el proveedor de clave pública), `TokenFactory::fake()`
  y `CheckCommand`.
- Tests nuevos en `tests/Auth/` simulando `APP_ENV=production`.
- Documentación: `docs/modules/auth.md` (sección de tests) y `src/Auth/Testing/keys/README.md`.
- Versión: 3.1.0 (minor). Sin efecto en API-Auth ni API-Maestros mientras no usen la clave de prueba en producción.
