# Design

## Context

La motivación está en el proposal (ver "Why") y los comportamientos exigidos, en los specs delta de
`login-flow`, `login-user`, `login-authorization`, `auth-user-token` y `module-system`.

**Cómo está hoy el código:**

- Los módulos heredan de `Support\ModuleServiceProvider` y se registran desde `UboCommonServiceProvider::MODULES`
  según `config/ubo-common.php`. No se conocen entre sí salvo por `Contracts/` y `Exceptions/`
  (`docs/framework.md`, regla 2). El módulo `sqlserver` ya viene apagado por defecto.
- Las llamadas a API-Auth pasan todas por `Auth\Infrastructure\ApiAuthClient` (marcada `@internal`): timeouts,
  `traceparent` y circuito compartido. `HttpServiceTokenProvider` es el modelo de cómo pedir tokens y cómo
  traducir los errores (`ServiceTokenException`).
- `Auth\AuthServiceProvider` ya conoce los validadores: `JwtValidator` (local), `IntrospectionValidator` y la
  clave de contenedor `INTROSPECTION`. `TokenValidator` se resuelve según `UBO_AUTH_STRATEGY`.
- `VerifiedToken` ya expone `roles`, `scopes`, `audiences` y `userId` (cambio `usuario-roles-y-audiencia`).
- IAM (API-Auth) entrega, al canjear el código, un token con `aud[0]` igual al client id del login, `sub` de la
  persona, `scopes` y `roles` de la aplicación del cliente. Un cliente `authorization-code` y uno
  `client-credentials` son clientes distintos en IAM, con credenciales distintas.

**Restricciones:**

- Los procesos de PHP-FPM no comparten memoria: el estado entre peticiones vive en la sesión de Laravel.
- Los módulos no responden errores, lanzan excepciones (regla 4); no se valida la configuración al registrar
  (spec `auth-token-validation`, "Sin validación al registrar los módulos").
- Esta parte entrega el login completo y usable, pero no renueva el token: eso es la parte 2.

## Goals / Non-Goals

**Goals:**

- Que un monolito active el módulo, ponga cuatro variables y tenga login, `@can` y logout contra IAM.
- Que el login no exija credenciales M2M ni consulte la introspección.
- Que la sesión pueda pasar a una bóveda y a renovación con refresh en la parte 2 sin cambiar el guard ni las
  rutas.

**Non-Goals:**

- Renovar el token, bóveda de tokens, revalidación contra IAM y tope absoluto de sesión (parte 2).
- `->asUser()` en ApiClient (otro cambio).
- Perfil de la persona (nombre y correo): hoy el token solo trae `sub`; depende de IAM.
- SPAs, clientes públicos, CORS y Laravel 8.

## Decisions

- **Módulo nuevo `login`, apagado por defecto**, con `ubo-login.php` y `LoginSettings`, como `sqlserver`.
  Registra rutas, un guard y habilidades de `Gate`, que no deben aparecer sin pedirlo. Alternativa descartada:
  meterlo en `auth`, que viene activo y es pasivo.
- **Canje y revocación viven en Auth**, como contrato `Contracts\UserTokenClient` con una implementación sobre
  `ApiAuthClient` (timeouts, circuito y traza ya resueltos), igual que `ServiceTokenProvider`. Login depende solo
  del contrato (regla 2). Recibe las credenciales como argumentos: Auth no sabe qué cliente es el de login.
  Excepción nueva `UserTokenRejected` (status, error, descripción), análoga a `ServiceTokenException`.
  Alternativa descartada: que Login llame a `ApiAuthClient` (clase interna de otro módulo).
- **Validación local del token recibido**, con un contrato `Auth\Contracts\LocalTokenValidator` (extiende
  `TokenValidator`) que `JwtValidator` implementa y `AuthServiceProvider` enlaza. El token llega directo de IAM por
  el canal de servidor a cambio de un código de un solo uso, así que la revocación no aporta nada en ese instante;
  con `hybrid` (el valor por defecto) exigiría credenciales M2M solo para entrar. La estrategia configurada se
  usará en la parte 2 para revalidar. Alternativa descartada: resolver `TokenValidator` y pedir
  `UBO_AUTH_STRATEGY=jwt` en los monolitos.
- **Datos de la sesión tras una clase `LoginSession`** que encapsula leer y escribir (usuario, roles, scopes,
  audiencias, client id, token de acceso, vencimiento) y los datos pendientes del login (`state`, verificador,
  destino, vencimiento), en claves de la sesión de Laravel. El guard solo habla con `LoginSession`; la parte 2
  cambia su implementación a una bóveda. No se guarda el refresh token: no se usa todavía y un secreto menos en
  la sesión es mejor.
- **`state` y verificador** con `random_bytes` (40 caracteres y 32 bytes en base64url), `hash_equals` para
  comparar y `pull` para que sean de un solo uso. El destino original se acepta solo si es relativo o del host de
  `APP_URL`: el valor lo fija el propio middleware, pero la ruta de login también lo admite por parámetro.
- **`redirect_uri` desde `APP_URL`**, no desde la petición: `route()` toma el host de la cabecera `Host`, que un
  atacante controla si el servidor no restringe hosts. Con proxies, `APP_URL` debe ser la dirección pública.
- **Rutas bajo `web`** (sesión, cookies y CSRF) con prefijo configurable (`iam` por defecto, para no chocar con un
  `/login` o `/auth` de la aplicación) y nombres fijos `ubo.login`, `ubo.login.callback` y `ubo.logout`. El
  callback es GET (llega de una redirección), así que el `state` hace de protección CSRF; el logout es POST.
- **Guard `ubo` registrado con `Auth::extend`** y declarado en `config('auth.guards.ubo')` si la aplicación no lo
  definió, sin tocar el guard por defecto. Sin proveedor de usuarios: el usuario sale de la sesión.
- **Autorización con `Gate::before`** que devuelve `true` si el usuario es `UboUser` y tiene el scope, y `null` en
  cualquier otro caso. Nunca `false`: no debe pisar las políticas de la aplicación. Los roles no son habilidades
  (un nombre de rol puede coincidir con uno de scope); se consultan con `hasRole()` y el middleware `ubo.role`.
- **`ubo.login` lanza `AuthenticationException` con `redirectTo`** hacia la ruta de login, en vez de redirigir él
  mismo: Laravel decide entre redirección y 401 según la petición, y recuerda la página pedida con
  `redirect()->guest()`.
- **Errores como excepciones**: `LoginFailed` (400 o 403) y `NoAccess` (403) heredan de las HTTP de Symfony, y la
  caída de IAM sigue siendo `AuthServerUnavailable`, que el módulo `problems` ya presenta como 503.
- **Sesión tras vencer el token, sin renovar**: el guard devuelve `null` cuando vence el token (menos
  `UBO_AUTH_LEEWAY`). El siguiente acceso pasa por IAM; si la sesión de IAM sigue abierta, vuelve sin pedir la
  clave. Es una solución provisoria que la parte 2 reemplaza.
- **Dependencias**: se declaran `illuminate/session` e `illuminate/routing` en `composer.json` (ya vienen con
  `laravel/framework`, así que no cambia lo que se instala) y se actualiza `composer.lock` solo en su hash.

## Risks / Trade-offs

- [Un token que vence a los 60 minutos cierra la sesión local: una persona que escribe un formulario largo puede
  perderlo al enviarlo] → es provisorio hasta la parte 2; documentarlo en `docs/modules/login.md`.
- [Con `SameSite=Strict` la cookie de sesión no viaja en la redirección de IAM y el callback falla por `state`
  ausente] → documentar `lax` (el valor por defecto de Laravel) y que el error de `state` ausente lo menciona.
- [El token vive en la sesión de servidor: quien lea la tabla o el almacén de sesiones lo ve] → recomendar
  `SESSION_ENCRYPT=true` y un almacén de sesiones privado; el controlador `cookie` se rechaza.
- [IAM limita `/oauth/token` a 120 por minuto por client id, compartido por todas las personas del monolito] →
  un 429 llega como `UserTokenRejected` con el status; se documenta como límite y se deja a IAM subirlo.
- [Un logout en otro sistema revoca el token, pero esta sesión no lo sabe hasta que venza (≤ 60 min)] → la parte 2
  agrega la revalidación periódica.
- [IAM y el monolito comparten `localhost` en desarrollo y las cookies no se aíslan por puerto] → nombres de
  cookie distintos (`SESSION_COOKIE`) o dominios `*.localhost`; se documenta.
- [Un `config/ubo-common.php` publicado sin la clave `login`] → la fusión profunda aporta `login=false`; un test
  lo cubre, porque el registro trata una clave ausente como módulo activo.

## Migration Plan

No hay migración: el módulo está apagado por defecto. Para usarlo, cada monolito: crea en IAM la aplicación, sus
roles y un cliente `authorization-code` con la `redirect_uri` del callback; define `UBO_COMMON_LOGIN=true`,
`UBO_AUTH_URL`, `UBO_LOGIN_PUBLIC_URL`, `UBO_LOGIN_CLIENT_ID` y `UBO_LOGIN_CLIENT_SECRET`; y cambia sus rutas a
`ubo.login`. Revertir es definir `UBO_COMMON_LOGIN=false`.

## Open Questions

- Si el login debería ofrecer una ruta de verificación de configuración (`ubo:login:check`, como
  `ubo:auth:check`). No cambia los specs ni las tareas; se decide al escribir la documentación.
