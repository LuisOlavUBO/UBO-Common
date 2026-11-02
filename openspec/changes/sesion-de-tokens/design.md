# Design

## Context

La motivación está en el proposal (ver "Why") y los comportamientos exigidos, en los specs delta de `login-user` y
`auth-user-token`.

**Cómo está hoy el código (parte 1, ya fusionada):**

- `Login\Infrastructure\LoginSession` guarda en la sesión de Laravel, bajo `ubo_login`, la persona, sus roles, scopes
  y audiencias, el token de acceso y su vencimiento; y bajo `ubo_login_pending`, el login en curso. Todo lo que
  lee o escribe pasa por esa clase.
- `UboGuard::user()` lee `LoginSession::current()` en cada llamada, sin memorizar, y devuelve `null` cuando vence el
  token (menos `UBO_AUTH_LEEWAY`). `LoginController` crea la sesión en `callback()` y la cierra en `logout()`.
- `Auth\Contracts\UserTokenClient` canjea el código y revoca el token (`HttpUserTokenClient` sobre `ApiAuthClient`).
  `HttpServiceTokenProvider` ya resuelve el mismo problema para el token M2M: caché con renovación antes de vencer,
  `Cache::lock` con espera, token de reserva si API-Auth no responde.

**Lo verificado contra un IAM real (API-Auth local):**

- El refresh con `grant_type=refresh_token` y el cliente de código responde 200 con tokens nuevos; cada refresh
  token es de un solo uso (reusarlo da 400 `invalid_grant`).
- **Renovar revoca el access token anterior** de inmediato: una petición que aún lleve el viejo recibe 401.
- Al cambiar el rol de la persona, la renovación recalcula `roles` y descarta scopes (no agrega).
- `POST /api/auth/v1/logout` con el token revoca también su refresh token.
- `/oauth/token` limita a 120 por minuto por cliente (429 con `Retry-After`, `application/problem+json`).

**Restricciones:**

- Los procesos de PHP-FPM no comparten memoria: el estado compartido es la caché de Laravel o la sesión.
- La sesión de Laravel se carga al empezar la petición y se guarda al terminar: dos peticiones en paralelo se
  pisan, gana la última escritura.
- Módulos entre sí solo por `Contracts/` (regla 2 de `docs/framework.md`); fallar cerrado en seguridad (regla 8).

## Goals / Non-Goals

**Goals:**

- Que una persona activa no pierda la sesión por el vencimiento del token ni por peticiones paralelas.
- Que un logout en otro sistema y la pérdida de roles se noten en minutos, no en una hora.
- Que `->asUser()` (otro cambio) pueda pedir "el token vigente de esta persona" sin saber de renovaciones.

**Non-Goals:**

- `->asUser()` y sus reintentos ante 401.
- Renovar por detrás (jobs, cron): se renueva en la petición que lo necesita.
- Un almacén propio: se usa el de caché de Laravel.

## Decisions

- **Los tokens viven en una bóveda de caché, no en la sesión.** La sesión guarda solo un identificador aleatorio
  (`handle`, 40 caracteres). Como el refresh rota y el access anterior se revoca al renovar, una escritura de sesión
  perdida (peticiones paralelas) deja a la persona con un refresh ya usado: `invalid_grant` y sesión terminada. En la
  bóveda hay una sola copia, que se escribe dentro del bloqueo. Alternativa descartada: tokens en la sesión con un
  bloqueo, que no evita que otra petición guarde su copia vieja de la sesión al terminar.
- **Almacén de la bóveda**: `UBO_LOGIN_STORE` (por defecto, el almacén de caché de la aplicación), con las mismas
  exigencias que el circuito de Auth: compartido entre servidores (redis o base de datos). Cada entrada guarda un
  JSON cifrado con `Crypt::encryptString` (tokens, persona, vencimiento, inicio del login, última comprobación),
  con TTL `UBO_LOGIN_REFRESH_TTL` (7200 s, la inactividad que tolera el refresh token de IAM), que se renueva al
  guardar. Si la entrada desaparece (caché vaciada), la persona vuelve a pasar por IAM, que es transparente si su
  sesión de IAM sigue abierta.
- **`LoginSession` conserva su interfaz** (`current()`, `start()`, `forget()`, `begin()`, `pull()`): `current()` lee
  la bóveda y `start()` la crea con un `handle` nuevo. El guard y las rutas casi no cambian. El login en curso
  (`ubo_login_pending`) sigue en la sesión: se escribe y se lee en peticiones distintas, no en paralelo.
- **Servicio `LoginTokens` (interno) con `current(handle)`**, que hace en orden: tope de duración, renovación si
  corresponde y revalidación si corresponde, y devuelve los datos o `null`. El guard lo llama en cada `user()`; no
  memoriza lo que resuelve, porque tras renovar, revalidar o terminar la sesión la bóveda ya guarda el estado nuevo y
  las llamadas siguientes no hacen nada. Otro cambio construirá `UserTokenProvider` sobre la misma pieza.
- **Lo que IAM no resolvió se pregunta una vez por petición.** Una petición consulta el guard varias veces
  (middleware, `Gate`, vistas), y si IAM no responde o limita las llamadas, cada consulta repetiría la renovación o la
  revalidación: con el 429 por cliente de IAM, eso agrava el límite. `LoginTokens` avisa (`unsettled()`) cuando dejó
  una pregunta a IAM abierta (renovación que siguió con el token vigente, o revalidación sin respuesta) y el guard
  recuerda ese resultado solo para ese objeto `Request` (un `WeakMap`). La siguiente petición vuelve a intentar. Lo
  que sí quedó resuelto (renovado, revalidado, sesión terminada) no se memoriza.
- **Renovación como en `HttpServiceTokenProvider`**: margen `UBO_LOGIN_REFRESH_MARGIN` (60 s), `Cache::lock` por
  `handle` (10 s, espera 5 s) y, ya dentro, se vuelve a leer la bóveda: si otra petición renovó mientras se
  esperaba, se usa su resultado. Si el almacén no tiene bloqueos, o la espera se agota, se renueva igual.
- **`invalid_grant` no siempre termina la sesión.** Si el bloqueo falló y dos peticiones renuevan a la vez, la
  segunda recibe `invalid_grant` porque la primera ya rotó el token. Antes de terminar la sesión se vuelve a leer la
  bóveda: si su refresh token cambió, otra petición ya renovó y se usa ese resultado.
- **El token renovado se valida como el del login** (`LocalTokenValidator`, mismo cliente, no es de sistema) y debe ser
  de la misma persona (`sub` igual). Los roles, scopes y audiencias de la sesión salen de él, así que la
  autorización sigue lo que IAM dice en cada renovación (los scopes nunca crecen, solo se descartan).
- **Roles vacíos al renovar = sin acceso**: se borra la bóveda y el guard devuelve `null`; la siguiente petición va a
  IAM, que le muestra su página de "sin acceso" y no devuelve a la aplicación. Se decidió así (en vez de una página
  propia) para que haya un único mensaje y no haya bucle.
- **IAM caído, circuito abierto o 429 al renovar**: no es el fin de la sesión. Con el token aún vigente se sigue con
  él (como el token de reserva del M2M); con el token vencido el guard devuelve `null` sin borrar la bóveda. El
  resultado es una redirección al login de IAM: si IAM no responde al navegador, la persona ve el error de su proxy
  o del navegador, y si el navegador llega pero este servidor no, el callback responde 503; el refresh token sigue
  guardado.
- **Revalidación por la estrategia configurada** (`TokenValidator`), cada `UBO_LOGIN_REVALIDATE` s, solo con
  `introspection`/`hybrid` y credenciales M2M (`AuthSettings::hasClientCredentials()`); con `jwt` solo comprobaría
  firma y vigencia, que ya se sabe. Si IAM no responde se sigue y se reintenta: la revalidación es un control extra, no
  la autenticación, y que IAM caiga no debe sacar a toda la gente. La introspección tiene su caché de 30 s, así que
  la carga sobre IAM es pequeña.
- **Tope absoluto** `UBO_LOGIN_MAX_AGE` (12 h): IAM no lo tiene (cada renovación suma 120 minutos), y una persona con
  la pestaña abierta tendría sesión para siempre. `0` lo apaga.
- **El logout usa el token vigente de la bóveda**, ya renovado: el que el login entregó hace horas está revocado.
  Después borra la entrada.
- **`UboLogin::actingAs()` escribe en la bóveda** con su API actual, para que los tests de las aplicaciones no cambien.
- **El canje devuelve el refresh token** (`refresh_token` nulo si IAM no lo entrega: la sesión se comporta como en la
  parte 1 y termina al vencer el token).

## Risks / Trade-offs

- [La bóveda en un almacén no compartido (array, file en varios servidores) pierde la sesión al cambiar de servidor]
  → documentarlo junto a `SESSION_DRIVER` y recomendar redis o base de datos; el síntoma es volver a IAM, no un fallo.
- [Cada renovación revoca el access token anterior: una petición en vuelo con el token viejo recibe 401 de una API]
  → el contrato de la bóveda siempre entrega el token más nuevo, y `->asUser()` volverá a leer la bóveda ante un 401
  antes de forzar una renovación.
- [El límite de 120 por minuto por cliente de IAM, compartido por todas las personas, puede dar 429 en picos de
  renovación] → el 429 no termina la sesión (hay margen de 60 s y el token sigue vigente); se documenta.
- [Un refresh token perdido por una escritura fallida de la bóveda (caché caída justo al renovar) deja el token
  rotado sin guardar] → la persona vuelve a pasar por IAM; se registra un `warning`.
- [Revalidar con `hybrid` exige credenciales M2M en el monolito] → es opcional: sin ellas no se revalida y el cierre de
  sesión en otro sistema se nota cuando vence o se renueva el token (≤ 60 min), cosa que se documenta.
- [Los tests de la parte 1 leen la sesión directamente] → pasan a leer la bóveda por una ayuda de pruebas.

## Migration Plan

La parte 1 todavía no se publicó, así que no hay sesiones de producción con el formato anterior. En desarrollo,
cualquier sesión previa se descarta: el guard no encuentra el `handle` y la persona vuelve a entrar por IAM.

## Open Questions

- Si el nombre de la caché de la bóveda debería poder compartir el almacén de Auth (`UBO_AUTH_CACHE_STORE`) por
  defecto, en vez del de la aplicación. No cambia los specs ni las tareas.
