# Proposal

## Why

IAM (API-Auth) ya autentica personas con Authorization Code + PKCE, pero ningún monolito Laravel de la UBO
puede usarlo sin escribir el flujo por su cuenta: redirección con `state` y PKCE, canje del código, sesión,
usuario autenticado, permisos con `@can` y cierre de sesión. Es código sensible que conviene tener una sola
vez en Common. Este cambio es la primera parte del módulo de login: el login, el usuario y la
autorización. La segunda parte (renovar el token y guardarlo en una bóveda) viene en otro cambio.

## What Changes

- Módulo nuevo `login`, **apagado por defecto** (`UBO_COMMON_LOGIN`), con su configuración `ubo-login`.
- Rutas de login, callback y logout del monolito: `state` y PKCE guardados en la sesión, canje del código
  contra IAM, validación del token recibido, sesión nueva (id regenerado) y redirección al destino original.
- Usuario autenticado (`UboUser`) con su guard `ubo`: identificador, roles, scopes y audiencias del token.
  Una persona sin roles en la aplicación no entra.
- Autorización: los scopes del token funcionan como habilidades de `Gate` (`@can`, `can:`), y un middleware
  exige autenticación y otro uno de varios roles.
- Logout: revoca el token en IAM, cierra la sesión local y manda a IAM a cerrar su sesión (o solo local).
- Auth: un contrato nuevo para obtener y revocar tokens de una persona (canje del código y revocación),
  con los timeouts, el circuito y la traza de las demás llamadas a API-Auth.
- Ayuda de pruebas `UboLogin::actingAs()` para que los monolitos prueben sus rutas sin IAM.
- En esta parte el token de acceso vive en la sesión del servidor y **no se renueva**: cuando vence, la
  sesión local termina y el siguiente acceso vuelve a pasar por IAM (transparente si la sesión de IAM sigue
  abierta). La renovación con refresh token, la bóveda, la revalidación y el tope de sesión van en la parte 2.
- Fuera de alcance: `->asUser()` en ApiClient (otro cambio), SPAs, clientes públicos, CORS y Laravel 8.
- No hay cambios **BREAKING**: el módulo está apagado por defecto.

## Capabilities

### New Capabilities

- `login-flow`: login con IAM por Authorization Code + PKCE (inicio, callback, errores) y logout.
- `login-user`: sesión del monolito y usuario autenticado (guard `ubo`), con su ayuda de pruebas.
- `login-authorization`: scopes como habilidades de `Gate`, y middleware de autenticación y de roles.
- `auth-user-token`: obtener y revocar tokens de una persona en IAM (canje del código y logout por el
  canal de atrás).

### Modified Capabilities

- `module-system`: el catálogo suma el módulo `login`, que viene desactivado por defecto.

## Impact

- Código nuevo en `src/Login/` (provider, `LoginSettings`, controladores, middleware, guard, `UboUser`,
  excepciones, `Testing/UboLogin`) y en `src/Auth/` (contrato `UserTokenClient` con su implementación sobre
  `ApiAuthClient`, y el validador local accesible por su clave de contenedor).
- `UboCommonServiceProvider::MODULES`, `config/ubo-common.php` y `composer.json` (`illuminate/session` y
  `illuminate/routing` como dependencias declaradas).
- Variables nuevas: `UBO_COMMON_LOGIN`, `UBO_LOGIN_PUBLIC_URL`, `UBO_LOGIN_CLIENT_ID`,
  `UBO_LOGIN_CLIENT_SECRET`, `UBO_LOGIN_REDIRECT_URI`, `UBO_LOGIN_PROMPT`, `UBO_LOGIN_ROUTE_PREFIX`,
  `UBO_LOGIN_HOME`, `UBO_LOGIN_LOGOUT_RETURN`.
- Requiere en IAM un cliente `authorization-code` de la aplicación del monolito, con su `redirect_uri`
  registrada. Las credenciales M2M (`UBO_AUTH_CLIENT_ID/SECRET`) no son necesarias para el login.
- Documentación: `docs/modules/login.md`, `docs/framework.md` (catálogo) y `CHANGELOG.md`.
- Versión: 3.2.0 (minor), junto con los roles ya fusionados; la parte 2 puede ir en el mismo release.
