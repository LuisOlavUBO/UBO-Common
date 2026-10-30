# Proposal

## Why

API-Auth ya pone los roles de la persona en la claim `roles` de sus tokens y en la respuesta de la
introspección, pero `VerifiedToken` no los expone: `JwtValidator` e `IntrospectionValidator` los ignoran.
El módulo de login para monolitos (entrega 3 del IAM central) decide el acceso por rol y por scope a partir
del token que acaba de validar, y sus tests necesitan emitir tokens de usuario con roles y con las
audiencias que emite IAM.

## What Changes

- `VerifiedToken` expone los roles del token (`roles` y `hasRole()`); sin la claim, la lista queda vacía.
- `JwtValidator` lee la claim `roles` y `IntrospectionValidator` el campo `roles` de la respuesta de
  introspección, también cuando el resultado viene de la caché y fue guardado antes de este cambio.
- `TokenFactory` emite, con `forClient()` y `forUser()`, tokens con la claim `roles`, y `forUser()` acepta
  además la lista de audiencias que emite IAM para un token de usuario (el client id y las aplicaciones
  dueñas de sus scopes).
- Sin cambios para quien no usa roles: el middleware `ubo.auth` no revisa roles ni cambia sus reglas, y los
  constructores y firmas existentes siguen funcionando.
- Fuera de alcance: revisar la audiencia de los tokens de usuario en el middleware (lo hará el cambio de
  llamadas a nombre del usuario, que es cuando una API empieza a aceptarlos) y el módulo de login.
- No hay cambios **BREAKING**: todo es opcional y con valores por defecto.

## Capabilities

### New Capabilities

Ninguna.

### Modified Capabilities

- `auth-token-validation`: el token verificado expone los roles, y los validadores JWT e introspección los
  leen.
- `auth-testing`: `forClient()` y `forUser()` pueden emitir roles, y `forUser()` las audiencias de un token
  de usuario.

## Impact

- Código: `src/Auth/Domain/VerifiedToken.php`, `src/Auth/Infrastructure/Validators/JwtValidator.php`,
  `src/Auth/Infrastructure/Validators/IntrospectionValidator.php` y `src/Auth/Testing/TokenFactory.php`.
- Tests en `tests/Auth/` (`VerifiedTokenTest`, `IntrospectionTest`, `EndpointsTest` o el archivo que cubra
  `forUser`, y los de `TokenFactory`).
- Documentación: `docs/modules/auth.md` (token verificado y tabla de `TokenFactory`) y `CHANGELOG.md`.
- Compatibilidad: `new VerifiedToken(...)` con los argumentos actuales sigue válido (su constructor es
  público). La caché de introspección con entradas anteriores sigue siendo legible. API-Maestros solo usa
  `VerifiedToken::from()` y `forUser()`/`forClient()` con los argumentos actuales, y no se ve afectado.
- Versión: 3.2.0 (minor). Puede publicarse junto con el módulo de login.
