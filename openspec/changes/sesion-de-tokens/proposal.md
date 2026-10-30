# Proposal

## Why

El módulo de login (`login-central`) deja el token de acceso en la sesión y no lo renueva: a los 60 minutos la
sesión del monolito termina y la persona vuelve a pasar por IAM, lo que puede hacerle perder un formulario a
medio llenar. Probado contra un IAM real, el refresh token del cliente de login funciona (120 minutos de
inactividad, de un solo uso y rotando) y cada renovación **revoca al instante el access token anterior**, así
que guardar los tokens en la sesión de Laravel se rompe con peticiones paralelas: la última escritura de sesión
puede devolver el refresh ya usado y dejar a la persona fuera. Tampoco hay forma de enterarse de que otro
sistema cerró la sesión central, ni un tope a la duración total de la sesión.

## What Changes

- Los tokens (access y refresh) pasan de la sesión a una **bóveda en el servidor** (almacén de caché), cifrada y
  con un identificador aleatorio que es lo único que queda en la sesión.
- El token de acceso se **renueva solo** con el refresh token, un poco antes de vencer, y una sola vez aunque
  lleguen peticiones en paralelo. Los roles, scopes y audiencias de la persona se actualizan con cada renovación.
- La sesión termina si IAM rechaza el refresh (`invalid_grant`), si la persona ya no tiene roles en la
  aplicación o si se alcanza un tope de duración. Si IAM no responde, se sigue con el token vigente.
- **Revalidación periódica** contra IAM (por la estrategia configurada) para enterarse de un logout hecho en
  otro sistema.
- Variables nuevas: `UBO_LOGIN_STORE`, `UBO_LOGIN_REFRESH_MARGIN`, `UBO_LOGIN_REVALIDATE`, `UBO_LOGIN_MAX_AGE` y
  `UBO_LOGIN_REFRESH_TTL`.
- Auth: `UserTokenClient` renueva con el refresh token, y el canje del código devuelve también el refresh token.
- El logout revoca el token vigente (el ya renovado) y borra la bóveda.
- Fuera de alcance: `->asUser()` en ApiClient (otro cambio, que usará la bóveda), SPAs, clientes públicos y CORS.
- No hay cambios **BREAKING**: la parte 1 aún no se publicó. Cambia la forma de lo guardado en la sesión, que no
  es parte de la interfaz pública.

## Capabilities

### New Capabilities

Ninguna.

### Modified Capabilities

- `login-user`: los tokens viven en una bóveda del servidor; la sesión se renueva, se revalida y tiene un tope,
  en vez de terminar al vencer el token de acceso.
- `auth-user-token`: Auth renueva un token con el refresh token y el canje del código devuelve el refresh token.

## Impact

- Código: `src/Login/` (bóveda, servicio de renovación con bloqueo, `LoginSession`, `UboGuard`, controlador de
  logout y del callback, `UboLogin::actingAs()`, `LoginSettings` y `ubo-login.php`) y `src/Auth/`
  (`UserTokenClient` y `HttpUserTokenClient`).
- Variables nuevas del módulo (ver arriba). El almacén de la bóveda debe ser compartido entre servidores
  (redis o base de datos) y soportar bloqueos.
- Tests: los de `tests/Login/` que leen la sesión directamente pasan a leer la bóveda.
- Documentación: `docs/modules/login.md` (se quita la limitación de la parte 1) y `CHANGELOG.md`.
- Dependencia de IAM, verificada contra uno local: el refresh con el cliente de código, la rotación, la
  revocación del token anterior y el 429 por cliente.
- Versión: 3.2.0 (minor), junto con la parte 1.
