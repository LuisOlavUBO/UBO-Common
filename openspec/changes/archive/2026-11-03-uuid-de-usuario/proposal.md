# Proposal

## Why

API-Auth agregó a los usuarios un `uuid` público e inmutable, en la claim `uuid` de los tokens de usuario y en la
introspección, con una regla para los satélites: **guardan el `uuid` para referirse a una persona, nunca el `id`
ni `sub`**, que es el autonumérico interno de IAM. Hoy Common solo entrega `sub`: `VerifiedToken::$userId` en las
APIs y `Auth::id()` / `UboUser::getAuthIdentifier()` en los monolitos. Un satélite que usa Common guardaría justo
el identificador que la regla prohíbe. Es el momento de cambiarlo: el módulo Login salió en la 3.2.0 y ningún
monolito lo usa todavía en producción.

## What Changes

- **Auth**: `VerifiedToken` expone `uuid`, leído de la claim `uuid` (validación local) o del campo `uuid` de la
  introspección, en minúsculas. Es `null` en tokens de sistema y en tokens de usuario emitidos antes de que
  API-Auth agregara la claim. `userId` no cambia (sigue siendo `sub`).
- **Login**: `Auth::id()` y `UboUser::getAuthIdentifier()` pasan a devolver el **`uuid`** de la persona; `sub`
  queda disponible en `UboUser::$sub`. **Cambio de comportamiento** del módulo Login (ver Impact).
- El login exige que el token traiga `uuid`: sin él, `LoginFailed` (400), que indica actualizar API-Auth.
- Una sesión abierta antes de este cambio (sin `uuid` en la bóveda) se renueva de inmediato para obtenerlo; si no
  se puede, la persona vuelve a pasar por IAM. Nunca se usa `sub` como respaldo.
- La renovación exige que el token renovado traiga el mismo `uuid` que la sesión (cuando la sesión ya lo tiene).
- **Pruebas**: `TokenFactory::forUser()` emite la claim `uuid` (por defecto, uno determinístico derivado del id,
  disponible con `TokenFactory::uuidFor($userId)`), y `UboLogin::actingAs(id: ...)` usa `id` como el `uuid` de la
  persona, con `sub` opcional.

## Capabilities

### New Capabilities

Ninguna.

### Modified Capabilities

- `auth-token-validation`: el token verificado expone `uuid`.
- `login-user`: el identificador del usuario autenticado es el `uuid`; sesiones sin `uuid`; usuario simulado.
- `login-flow`: el token recibido en el login debe traer `uuid`.
- `auth-testing`: el token de usuario de prueba lleva la claim `uuid`.

## Impact

- **Código**: `src/Auth/Domain/VerifiedToken.php`, `JwtValidator`, `IntrospectionValidator`, `TokenFactory`;
  `src/Login` (`LoginController`, `LoginTokens`, `TokenVault`, `UboGuard`, `UboUser`, `UboLogin`).
- **Compatibilidad**: `Auth::id()` del guard `ubo` cambia de `sub` a `uuid`. Se publica como minor (3.3.0) porque el
  módulo Login no tiene usuarios en producción, con un aviso destacado en el CHANGELOG. Si algún monolito ya lo
  usara y guardara `Auth::id()`, debería migrar esos datos o este cambio debería ir como 4.0.0.
- **Orden de despliegue**: API-Auth con la claim `uuid` (commit `59a142a`) **antes** que esta versión en los
  monolitos; si no, ningún login prospera (`LoginFailed`).
- **Documentación**: `docs/modules/auth.md`, `docs/modules/login.md`, `docs/sso.md` (tabla de claims) y CHANGELOG.
