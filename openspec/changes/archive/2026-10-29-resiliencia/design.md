# Design

## Context

La motivación está en el proposal (ver "Why"), y los comportamientos exigidos, en los specs delta de
`auth-token-validation`, `auth-service-token` y `api-client`.

**Cómo está hoy el código:**

- **Auth.** Tres clases llaman a API-Auth y cada una arma su propio `PendingRequest` (timeout y
  `traceparent`) y traduce sus propios errores: `HttpPublicKeyProvider`, `IntrospectionValidator` y
  `HttpServiceTokenProvider`. No hay timeout de conexión ni estado compartido entre llamadas.
- **ApiClient.** La política de reintentos está en `PendingUboRequest::prepare()`, mediante el callback
  `when` de `retry()` de Laravel. Ese mismo callback renueva el token ante un 401 llamando a `withToken()`
  sobre el `PendingRequest`, así que modificar la petición entre intentos ya funciona, tanto en
  síncrono como en asíncrono.
- **Configuración.** Ambos módulos leen su configuración tipada (`AuthSettings` y `ApiDefinition`), que
  recibe las claves nuevas por el merge profundo (`ConfigMerge`).

**Restricciones:**

- Los procesos de PHP-FPM no comparten memoria, así que el único estado compartido disponible es la
  caché de Laravel.
- `docs/framework.md` exige que los módulos no se conozcan entre sí salvo por `Contracts/`, y deja
  `Support/` como base común.

## Goals / Non-Goals

**Goals:**

- Un único mecanismo de circuit breaker, reutilizado por Auth y ApiClient.
- Que la caída del almacén de caché nunca bloquee una llamada: el circuito es una optimización, no un
  requisito para llamar.
- Centralizar en un solo punto cómo se llama a API-Auth, para aplicar el timeout de conexión y el
  circuito una sola vez.

**Non-Goals:**

- Bulkheads o límites de concurrencia por API.
- Métricas (Prometheus u otras). Solo se registran las aperturas y los cierres en el log.
- Cambiar `ProblemRenderer` (ver "Risks").
- Cifrar el token en caché (hallazgo 8).

## Decisions

### 1. `Support\CircuitBreaker` sobre la caché de Laravel, sin dependencias nuevas

Una clase `CircuitBreaker` recibe un nombre, un `Repository` de caché, `failures`, `cooldown` y un
logger. Expone `allowsRequest()`, `recordSuccess()`, `recordFailure()` y `middleware()`. Guarda tres
claves por circuito:

- `…:failures`: contador con `add` + `increment`, ambos atómicos en Redis y en base de datos. Expira
  `cooldown` segundos después del primer fallo, así que fallos aislados muy separados no se acumulan.
- `…:open`: existe mientras el circuito está abierto, con TTL igual a `cooldown`. La expiración la
  resuelve el propio almacén, lo que evita comparar relojes entre servidores.
- `…:probe`: un candado tomado con `add` por el único proceso que hace la llamada de prueba cuando
  `open` ya expiró pero `failures` sigue sobre el umbral (estado "semiabierto").

`recordSuccess()` borra `failures` y `probe`. `recordFailure()` incrementa el contador y, al llegar al
umbral o si la prueba falla, crea `open` y libera `probe`. Las transiciones se registran una sola vez,
con un `add` sobre una clave de "ya registrado".

**Cualquier excepción del almacén se captura y el circuito se trata como cerrado** (falla abierto). Si
Redis cae, la app sigue llamando como hoy.

**Alternativas descartadas:**

- Un paquete como `ackintosh/ganesha`: agrega una dependencia y otra configuración para un mecanismo
  de unas 80 líneas.
- Estado en memoria del proceso: con FPM, cada worker aprendería la caída por separado, que es justo lo
  que hay que evitar.

### 2. Medir el resultado de cada intento con un middleware de Guzzle

`CircuitBreaker::middleware()` devuelve un middleware de Guzzle que se agrega con
`PendingRequest::withMiddleware()`. Registra éxito ante un 2xx, 3xx o 4xx, y fallo ante un 5xx o una
excepción de red, en cada intento, tanto síncrono como asíncrono. También registra fallo cuando el
handler lanza la excepción en vez de devolver una promesa rechazada, como hacen los stubs de
`Http::fake()`. En ApiClient se agrega una sola vez, en el constructor de `PendingUboRequest`: la copia
de la petición que hace cada verbo comparte la colección de middleware, así que agregarlo por verbo lo
duplicaría.

**Alternativa descartada:** registrar los fallos en el callback `when`. Ese callback solo ve los fallos
y nunca los éxitos, así que no podría cerrar el circuito.

La comprobación `allowsRequest()` se hace **antes** de pedir el token y de armar la petición, para que
una API caída no cueste ni una llamada a API-Auth.

### 3. Un solo circuito hacia API-Auth, en una clase `Auth\Infrastructure\ApiAuthClient`

`ApiAuthClient` arma la petición base con `connectTimeout`, `timeout` y `traceparent`, consulta el
circuito y lanza una excepción interna `ApiAuthUnreachable` cuando está abierto o hay un error de red.
Las tres clases que hoy llaman a API-Auth pasan a usarlo y traducen ese error a su excepción pública:
`AuthServerUnavailable` o `ServiceTokenException`. Así se elimina la duplicación actual.

Hay **un solo circuito llamado `api-auth`**, no uno por endpoint. Los tres endpoints viven en el mismo
servicio y caen juntos. Además, la clave pública ya tiene su propio respaldo: conserva la clave anterior
si la descarga falla.

`ubo:auth:check` usa `ApiAuthClient::withoutCircuit()`: llama siempre a API-Auth y no registra
resultados, para que un diagnóstico no abra ni cierre el circuito.

### 4. Token de reserva: la entrada en caché guarda sus dos fechas

La entrada pasa de ser un string a `['token', 'refresh_at', 'expires_at']`, con TTL igual a
`expires_in`. Antes de `refresh_at` se devuelve sin consultar a API-Auth. Después se intenta renovar, y
si la renovación lanza `ApiAuthUnreachable` y `expires_at` es futuro, se devuelve el token anterior. Un
4xx de API-Auth sigue lanzando la excepción.

El reloj sale de `ClockInterface` (`LaravelClock`), lo que permite probarlo con `travel()`.

Durante el despliegue pueden quedar en caché entradas antiguas, que son strings. Se aceptan como
vigentes hasta que expiren, igual que hoy.

### 5. El modo degradado vive en `HybridValidator`

Con `degradedMode`, si la validación remota lanza `AuthServerUnavailable`, se devuelve el
`VerifiedToken` local y se registra un `warning`, como máximo uno por minuto gracias a un `add` en
caché, para no llenar el log.

No se toca `IntrospectionValidator`: en la estrategia `introspection` no hay validación local en la que
apoyarse, y el spec excluye ese caso.

**Alternativa descartada:** aplicarlo en el middleware. Habría que distinguir allí qué validador
falló, y el middleware dejaría de depender solo del contrato `TokenValidator`.

### 6. Tiempo total y espera en `PendingUboRequest`

- **Inicio del reloj.** `run()` crea un `CallBudget` al invocar el verbo (en las llamadas asíncronas,
  al crear la promesa). Lee la hora de `Date` de Laravel en vez de `hrtime()`, para que los tests la
  muevan con `travel()`.
- **Primer intento.** Si el timeout es mayor que el `deadline` configurado, el tiempo total pasa a ser
  ese timeout. Un `->deadline()` explícito siempre manda y, si es menor, acorta el primer intento.
- **Callback `when`.** Ante un fallo reintentable:
  1. Calcula la espera exponencial con jitter, de la forma `delay/2 + random(0, delay/2)`, con
     `random_int`.
  2. Si `espera + 1 s` no cabe en el tiempo restante, marca `deadlineExceeded` y devuelve `false`.
  3. Si cabe, ajusta `$request->timeout(restante - espera)` y deja la espera calculada en una variable
     que lee el closure `delay`. Laravel invoca `when` antes que `delay`.
- **Al terminar.** Si `deadlineExceeded` está marcado, `run()` y `UboPromise::wait()` envuelven el
  resultado en `UboApiException::deadlineExceeded()`, con la causa original como `previous`.

**Alternativa descartada:** la opción `timeout` de Guzzle sobre el total. No cubre las esperas entre
intentos ni la renovación del token.

### 7. Valores por API en `ApiDefinition`

`ApiDefinition::fromConfig()` toma cada clave de `apis[$name]` si es válida, si no de `defaults`, y si
no de la constante del paquete.

Las funciones que validan valores (`positiveInt`, `nonNegativeInt`, `filledString`) se mueven de
`AuthSettings` a `Support\ConfigValue` para usarlas en ambos módulos. `AuthSettings` las sigue usando
sin cambiar su comportamiento.

`ApiDefinition` agrega `deadline`, `retryMaxDelay` y la configuración del circuito.
`PendingUboRequest::deadline(int $segundos)` es un método propio, no un `__call`.

### 8. Circuitos de ApiClient y `UboApi::fake()`

`ApiClientManager` crea los circuitos con el almacén `ubo-apis.cache_store` y el prefijo
`ubo-apis:circuit:`. Al llamar a `fake()`, el gestor reemplaza ese almacén por un `Repository` nuevo
sobre `ArrayStore`. Así cada test parte con todos los circuitos cerrados, sin tener que enumerar claves.

Con el circuito abierto:

- `UboClient` lanza `UboApiException::circuitOpen()` de inmediato.
- `UboAsyncClient` devuelve un `UboPromise` sobre una promesa ya rechazada, sin enviar nada.

### Valores por defecto

| Valor | Defecto |
|---|---|
| `ubo-apis.defaults.deadline` | 30 s |
| `ubo-apis.defaults.retry_max_delay` | 2000 ms |
| `ubo-apis.defaults.circuit_breaker` | `enabled=true`, `failures=5`, `cooldown=30` |
| `UBO_AUTH_CONNECT_TIMEOUT` | 2 s |
| `UBO_AUTH_CIRCUIT_FAILURES` / `UBO_AUTH_CIRCUIT_COOLDOWN` | 5 / 30 |
| `UBO_AUTH_DEGRADED_MODE` | `false` |

El `connect_timeout` de ApiClient se queda en 5 s para no cambiar el comportamiento actual.

## Risks / Trade-offs

- **[Almacén `file` o `array`]** Cada servidor (con `file`) o proceso (con `array`) tiene su propio
  circuito y detecta la caída más tarde.
  → Se documenta en las guías de Auth y ApiClient y en `.env.example`, y se recomienda Redis o base de
  datos.
- **[Ráfagas]** Con mucho tráfico, 5 intentos fallidos se acumulan en milisegundos, y un corte breve
  abre el circuito 30 s.
  → `failures` y `cooldown` se pueden configurar por API. El enfriamiento corto (30 s) y la prueba
  única acotan el impacto.
- **[Redis caído]** Las operaciones del circuito lanzarían excepciones.
  → El circuito falla abierto (decisión 1) y la llamada sigue como hoy.
- **[Modo degradado]** Mientras dura la caída se aceptan tokens revocados.
  → Está apagado por defecto, solo aplica a `hybrid` y deja un `warning`. La decisión de activarlo es de
  cada aplicación.
- **[Token de reserva]** Si API-Auth está caído y el token propio fue revocado, se sigue enviando ese
  token hasta su expiración.
  → La API destino lo rechazará con 401 si consulta la revocación, que es el mismo resultado que sin
  reserva.
- **[Log por petición]** `ProblemRenderer` registra un `warning` en cada `AuthServerUnavailable`, así
  que con el circuito abierto hay una línea por petición rechazada.
  → No se cambia en este cambio. El mensaje indica "circuito abierto" para distinguirlo, y queda como
  mejora del hallazgo 10.
- **[Precisión del tiempo total en asíncrono]** El tiempo de un intento lo controla curl multi, que
  puede pasarse unos cientos de milisegundos.
  → Los tests verifican el límite con tolerancia (`deadline + 1 s`).
- **[Interacción con `Http::fake()`]** Hay que confirmar que el middleware de Guzzle se ejecuta con las
  respuestas simuladas.
  → Hay una tarea explícita de verificación con un test antes de construir sobre él.

## Migration Plan

1. **Publicar 3.1.0 (minor).** Las apps solo ejecutan `composer update ubo/common`. La configuración
   publicada recibe las claves nuevas por el merge profundo.
2. **Recomendación operativa:** usar un almacén compartido (`UBO_AUTH_CACHE_STORE`,
   `ubo-apis.cache_store`).
3. **Rollback:**
   - Volver a `ubo/common` 3.0.x con `composer`. Las entradas de token en el formato nuevo (arreglos)
     no son strings, así que la 3.0.x las ignora y pide un token nuevo, lo que es seguro.
   - Para desactivar solo el circuito de ApiClient: `circuit_breaker.enabled=false`.

## Open Questions

- Si conviene exponer el estado de los circuitos en el endpoint de salud. Se puede agregar después sin
  cambiar estos specs.
