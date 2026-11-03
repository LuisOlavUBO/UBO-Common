# Design

## Context

La motivación está en el proposal (ver "Why"); los comportamientos exigidos, en los specs delta de `api-client`,
`login-user` y `auth-token-validation`.

**Cómo está hoy el código:**

- **Login.** `LoginSession` guarda en la sesión solo el `handle` de la bóveda. `TokenVault` guarda el token
  cifrado y `LoginTokens::current($handle)` lo entrega vigente: aplica el tope de sesión, renueva dentro del
  margen y revalida.
- **El guard `ubo`** llama a `LoginTokens::current()` en cada `user()`. Cuando IAM no resolvió (no respondió o
  limitó con 429), recuerda el resultado solo para ese `Request` (un `WeakMap`), para no repetir la consulta en
  la misma petición.
- **ApiClient.** `PendingUboRequest::prepare()` pone el token propio (`ServiceTokenProvider`) y, ante un 401,
  lo descarta y pide otro dentro del callback `when`.
- **Auth.** El middleware `Authenticate` revisa el `aud` solo si el token es de sistema
  (`isMachineToken()`).

**Restricciones:** los módulos se conocen solo por `Contracts/` (regla 2 de `docs/framework.md`), y el módulo
`login` viene apagado por defecto: ApiClient tiene que funcionar sin él mientras no se use `->asUser()`.

## Goals / Non-Goals

**Goals:**

- `->asUser()` reutiliza toda la política de ApiClient: circuito, tiempo total, reintentos y traza.
- Ninguna consulta extra a IAM por pedir el token de la persona en una petición que ya resolvió el guard.
- Un 401 por un token que otra petición acaba de renovar se resuelve sin terminar la llamada.

**Non-Goals:**

- Colas y comandos, y llamar en nombre de otra persona.
- Achicar los scopes del token de la persona.
- Cambiar cómo IAM emite los tokens de usuario.

## Decisions

### 1. Contrato `Login\Contracts\UserTokenProvider`

```php
interface UserTokenProvider
{
    /** @throws AuthenticationException sin una persona con sesión */
    public function token(): string;

    /** El token vigente distinto de $rejected, renovado si hace falta; null si no hay otro. */
    public function renewedAfter(string $rejected): ?string;
}
```

Es la primera carpeta `Contracts/` del módulo Login. ApiClient depende solo de ella, igual que de
`Auth\Contracts\ServiceTokenProvider`.

### 2. La persona se resuelve con el guard y el token se lee de la bóveda

La implementación (`Login\Infrastructure\SessionUserTokenProvider`, `@internal`) primero pregunta al guard
`ubo` si hay una persona (`Auth::guard('ubo')->user()`) y después lee la entrada de la bóveda tal como quedó
(`LoginSession::current()`).

- El guard ya renovó si correspondía, y su `WeakMap` evita repetir en la misma petición una consulta a IAM que
  quedó sin respuesta.
- Si el guard devuelve `null`, o no hay entrada en la bóveda, se lanza `AuthenticationException`. Esto cubre
  la ruta sin sesión, la sesión terminada y la petición sin sesión de Laravel (cola, comando).
- Una persona forzada con `$this->actingAs($user, 'ubo')` de Laravel no tiene bóveda, así que no tiene token:
  las pruebas usan `UboLogin::actingAs()`, que sí la escribe. Se documenta.

**Alternativa descartada:** llamar directamente a `LoginTokens::current()`. Duplicaría las renovaciones y
revalidaciones ya hechas por el guard, y no vería su memoria de lo no resuelto.

### 3. `LoginTokens::renewedAfter($handle, $rejected)`

Corre bajo el mismo bloqueo de la bóveda que la renovación normal y relee la entrada:

- Si el `access_token` ya no es `$rejected`, devuelve la entrada actual: otra petición renovó.
- Sin refresh token (persona simulada, IAM no lo entregó), devuelve `null`.
- Si no, renueva **sin mirar el margen**. Reutiliza `renew()` con un indicador `force` y los mismos desenlaces:
  `invalid_grant` pasa por `rotated()`, IAM caído o 429 pasa por `keep()` (y el provider devuelve `null`
  porque el token no cambió), y un token renovado inválido termina la sesión.

El token solo se renueva a la fuerza cuando una API lo rechazó, como máximo una vez por llamada. Si llegan
varias peticiones a la vez, el bloqueo hace que IAM reciba una sola renovación.

### 4. `->asUser()` en `PendingUboRequest`

- `asUser(): static` activa el modo. El constructor recibe un `Closure(): UserTokenProvider` perezoso desde
  `ApiClientManager`: se resuelve solo al usar `->asUser()`, y lanza `LogicException` si el módulo `login` está
  apagado. Así ApiClient sigue funcionando sin Login.
- **Orden en `run()`:** primero la persona (`AuthenticationException` antes que nada), después el circuito, y
  después `prepare()`. Sin sesión, el error es "inicia sesión" aunque la API esté caída.
- **`prepare()`** usa el token de la persona en lugar de `ServiceTokenProvider` (no pide token a API-Auth).
  En el callback `when`, un 401 llama a `renewedAfter($actual)`: con un token distinto, `withToken()` y
  reintenta; con `null`, devuelve `false` y la respuesta 401 termina en `UboApiException`. El resto del
  callback (transitorios, espera y tiempo total) no cambia.
- `AuthenticationException` no se envuelve en `UboApiException`: tiene que llegar al manejador de Laravel para
  que redirija al login.
- En `callAsync()`, `run()` corre al crear la promesa, así que el token es el vigente en ese momento.

### 5. Audiencia de los tokens de usuario (hallazgo 7)

En `Authenticate` se quita la condición `isMachineToken()`: con `UBO_AUTH_API` definida, todo token debe
nombrarla en su `aud`. `TokenFactory::forUser()` ya emite los tokens para la API bajo prueba, así que los tests
de las aplicaciones no cambian. El orden de las revisiones no cambia: `user_token_not_allowed` sigue antes que
`wrong_audience`.

### 6. Pruebas en las aplicaciones

`UboLogin::actingAs()` ya escribe la bóveda con el token `test-access-token` y sin refresh token. Con
`UboApi::fake()`, `->asUser()` envía ese token. No hace falta una ayuda nueva.

## Risks / Trade-offs

- **[Una API con `UBO_AUTH_API` que hoy acepta tokens de usuario sin su nombre en `aud` empieza a responder
  401]** → La opción `UBO_AUTH_ALLOW_USER_TOKENS` viene apagada y ninguna API conocida la activa. Se avisa en el
  CHANGELOG, con el remedio: que el rol del sistema que llama incluya un scope de esa API, para que IAM ponga su
  nombre en `aud`.
- **[La API destino responde 403 porque el rol de la persona en el monolito no trae sus scopes]** → No es un
  error de Common: el token solo trae lo de la aplicación donde inició sesión. Se documenta en `api-client.md` y
  `sso.md` con un ejemplo de `oauth:scope … --role=`.
- **[Un 429 de IAM al forzar la renovación]** → `keep()` no termina la sesión. La llamada termina con el 401
  original, y la siguiente petición vuelve a intentar.
- **[El token de la persona sale del monolito hacia otras APIs]** → Es el propósito. Viaja solo en el header
  `Authorization`, nunca al navegador, y nunca se registra en el log. Con el hallazgo 7 cerrado, solo sirve en
  las APIs de su `aud`.

## Migration Plan

1. Publicar 3.3.0 (minor). Las aplicaciones que no usan `->asUser()` ni aceptan tokens de usuario no cambian.
2. Para usarlo:
   - En la API destino: `UBO_AUTH_ALLOW_USER_TOKENS=true` y `UBO_AUTH_API`.
   - En IAM: los scopes de la API destino en los roles de la aplicación del monolito.
   - En el monolito: el módulo `login` activo.
3. **Rollback:** volver a 3.2.x quita `->asUser()` y la revisión de `aud` en los tokens de usuario.
