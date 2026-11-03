# Proposal

## Why

Un monolito con login (módulo Login) solo puede llamar a las APIs UBO con su token de máquina. La API
destino ve al sistema y no a la persona, así que no puede aplicar los permisos de quien está conectado ni
registrar quién hizo cada cambio. La bóveda de `sesion-de-tokens` ya guarda y renueva el token de la
persona, y su design dejó `->asUser()` en ApiClient como siguiente paso.

Cuando los tokens de usuario empiecen a viajar entre APIs, el hallazgo 7 deja de ser teórico: hoy una API
no revisa el `aud` de un token de usuario, así que el token de una persona emitido para un sistema serviría
en cualquier API que acepte tokens de usuario.

## What Changes

- **ApiClient**: `->asUser()` en `UboApi::call()` y `UboApi::callAsync()`. La llamada sale con el token de
  la persona que tiene sesión en el monolito, en vez del token de máquina. Siguen aplicando el circuito por
  API, el tiempo total, los reintentos y la traza.
- Ante un **401** de la API destino, `->asUser()` relee el token de la persona: si cambió (otra petición lo
  renovó), reintenta con él; si no, fuerza una renovación con el refresh token y reintenta una vez.
- **Sin persona con sesión** (ruta sin `ubo.login`, sesión vencida que no se pudo renovar, cola o comando),
  `->asUser()` lanza `AuthenticationException`: en una ruta web, Laravel redirige al login de IAM.
- Con el módulo `login` apagado, `->asUser()` lanza `LogicException`, como ApiClient sin el módulo `auth`.
- **Login**: contrato nuevo `UserTokenProvider` con el token vigente de la persona de la petición y la
  renovación forzada tras un rechazo. `LoginTokens` aprende a renovar sin esperar el margen.
- **Auth (hallazgo 7)**: con `UBO_AUTH_API` definida, el middleware `ubo.auth` también exige que un **token
  de usuario** nombre a esa API en su `aud`, como ya hace con los tokens de sistema. **Cambio de
  comportamiento**: una API con `UBO_AUTH_API` y `UBO_AUTH_ALLOW_USER_TOKENS=true` que hoy recibe tokens de
  usuario sin su nombre en el `aud` empezará a responder 401 `wrong_audience`. No se marca **BREAKING**
  porque ninguna API conocida acepta tokens de usuario hoy (la opción viene apagada), pero se avisa en el
  CHANGELOG.
- Fuera de alcance: colas y comandos (no hay sesión: error claro), llamar en nombre de otra persona, y la
  combinación con `->scopes()` del cambio en pausa `scopes-por-llamada`.

## Capabilities

### New Capabilities

Ninguna.

### Modified Capabilities

- `api-client`: llamada en nombre de la persona con sesión, renovación de su token ante 401, error sin
  persona con sesión y dependencia del módulo login.
- `login-user`: token vigente de la persona para llamar a otras APIs y renovación forzada tras un rechazo.
- `auth-token-validation`: la audiencia se revisa también en los tokens de usuario (hallazgo 7).

## Impact

- **Código**: `src/Login` (contrato `UserTokenProvider` en `Contracts/`, su implementación sobre la bóveda y
  `LoginTokens`), `src/ApiClient` (`PendingUboRequest`, `ApiClientManager`, `UboApi`) y
  `src/Auth/Http/Middleware/Authenticate.php`.
- **Configuración fuera de Common** (se documenta):
  - En la API destino: `UBO_AUTH_ALLOW_USER_TOKENS=true` y `UBO_AUTH_API` con su nombre en IAM.
  - En IAM: los roles de la aplicación del monolito deben incluir los scopes de la API destino. El token de
    usuario solo trae lo de la aplicación donde la persona inició sesión, y su `aud` nombra a las
    aplicaciones dueñas de esos scopes.
- **Tests**: `tests/ApiClient`, `tests/Login` y `tests/Auth/MiddlewareTest.php`.
- **Documentación**: `docs/modules/api-client.md`, `docs/modules/login.md`, `docs/modules/auth.md`,
  `docs/sso.md` y el CHANGELOG.
- **Versión**: 3.3.0 (minor).
