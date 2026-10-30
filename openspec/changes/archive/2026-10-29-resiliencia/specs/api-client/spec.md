# Spec Delta

## MODIFIED Requirements

### Requirement: Petición preconfigurada
Cada petición SHALL usar la URL indicada como base (sin `/` final), `Accept: application/json`, los
valores de la API (`timeout` 10 s, `connect_timeout` 5 s y `headers`, ver "Valores por API") y el header
`traceparent` si el módulo `tracing` está activo. Se puede encadenar cualquier método de configuración
del cliente HTTP de Laravel antes del verbo.

#### Scenario: Header y timeout puntuales
- **WHEN** se llama a `->withHeaders(['If-None-Match' => $etag])->timeout(30)->get(...)`
- **THEN** la petición lleva ese header y usa 30 segundos de timeout

#### Scenario: Valores por defecto globales
- **WHEN** la aplicación cambia `ubo-apis.defaults.timeout` y no define valores para la API
- **THEN** el cambio se aplica a esa API

### Requirement: Espera entre reintentos
La espera antes del reintento número n SHALL calcularse como `min(retry_max_delay, retry_delay × 2^(n-1))`
milisegundos (`retry_delay` 200, `retry_max_delay` 2000 por defecto) y luego elegirse al azar entre la
mitad de ese valor y el valor completo. MUST ser cero en la renovación por 401, y ninguna espera MUST
superar el tiempo que le queda a la llamada (ver "Tiempo total por llamada").

#### Scenario: Dos reintentos
- **WHEN** un GET falla por error de conexión tres veces con los valores por defecto
- **THEN** espera entre 100 y 200 ms antes del segundo intento y entre 200 y 400 ms antes del tercero

#### Scenario: Tope de la espera
- **WHEN** una API tiene `retries=6` y un GET falla en todos los intentos
- **THEN** ninguna espera supera los 2000 ms

#### Scenario: Esperas distintas entre procesos
- **WHEN** dos procesos reintentan a la vez la misma llamada fallida
- **THEN** sus esperas no son necesariamente iguales

### Requirement: Excepción de llamada fallida
`UboApiException` SHALL exponer `api`, `status`, `errorCode` (campo `error`), `title`, `detail` (de
`detail`, `message` o `error_description`), `response`, `isConnectionError()`, `isCircuitOpen()` y
`isDeadlineExceeded()`. Su mensaje MUST tener la forma `[<api>] HTTP <status> <error>: <detalle>` cuando
hubo respuesta.

#### Scenario: Mensaje de un problem
- **WHEN** `api-maestros` responde 404 con `error=NO_ENCONTRADO` y `detail='La comuna no existe.'`
- **THEN** el mensaje es `[api-maestros] HTTP 404 NO_ENCONTRADO: La comuna no existe.`

#### Scenario: Respuesta sin JSON
- **WHEN** la API responde 502 con un cuerpo HTML
- **THEN** se lanza `UboApiException` con `status=502` y `errorCode` nulo

#### Scenario: Circuito abierto
- **WHEN** la llamada no se envía porque el circuito de la API está abierto
- **THEN** la excepción tiene `status=null`, `isCircuitOpen()` igual a `true` e `isConnectionError()` igual a `false`

### Requirement: Llamadas asíncronas
`UboApi::callAsync($api, $url)` SHALL ofrecer los mismos verbos, iniciar la petición de inmediato y
devolver un `UboPromise` cuyo `wait()` devuelve la respuesta o lanza `UboApiException`, con la misma
política de token, reintentos, tiempo total y circuit breaker. El tiempo total MUST contarse para cada
promesa desde que se crea. Las promesas pendientes MUST avanzar en paralelo.

#### Scenario: Trabajo mientras la petición está en curso
- **WHEN** se crea una promesa con `callAsync(...)->get(...)` y luego se llama a `wait()`
- **THEN** `wait()` devuelve la respuesta 2xx de la API

#### Scenario: Circuito abierto en una llamada asíncrona
- **WHEN** se crea una promesa para una API con el circuito abierto
- **THEN** no se envía ninguna petición y `wait()` lanza `UboApiException` con `isCircuitOpen()` igual a `true`

#### Scenario: Tiempo total en llamadas paralelas
- **WHEN** se esperan con `all()` dos promesas de una API cuyo `deadline` es 15 s y ambas fallan en todos los intentos
- **THEN** `all()` termina en unos 15 s, no en la suma de los dos tiempos

## ADDED Requirements

### Requirement: Valores por API
Los valores de una llamada SHALL salir de `ubo-apis.apis.<nombre de la API>` y, para cada clave que no
esté ahí, de `ubo-apis.defaults`: `timeout`, `connect_timeout`, `deadline`, `retries`, `retry_delay`,
`retry_max_delay`, `headers` y `circuit_breaker`. Un valor vacío, no numérico o fuera de rango MUST usar
el de `defaults`, y si este también es inválido, el del paquete.

#### Scenario: API con valores propios
- **WHEN** la configuración tiene `apis.api-reportes.timeout = 30` y `defaults.timeout = 10`
- **THEN** las llamadas a `api-reportes` usan 30 segundos y las demás 10

#### Scenario: Valor parcial
- **WHEN** `apis.api-reportes` solo define `timeout`
- **THEN** los reintentos, la espera, el tiempo total y el circuito de `api-reportes` usan los de `defaults`

#### Scenario: Configuración publicada de la versión 3.0.0
- **WHEN** la aplicación publicó `ubo-apis.php` antes de este cambio
- **THEN** las llamadas usan los valores por defecto del paquete para `deadline`, `retry_max_delay` y `circuit_breaker`

### Requirement: Tiempo total por llamada
Cada llamada SHALL terminar dentro de `deadline` segundos (30 por defecto) desde que se invoca el verbo,
contando todos los intentos, la renovación del token y las esperas. Ningún intento MUST usar un timeout
mayor que el tiempo restante, y no MUST hacerse un reintento cuya espera no quepa en él. Al agotarse,
MUST lanzarse `UboApiException` con `isDeadlineExceeded()` igual a `true` y la causa del último intento.

#### Scenario: API lenta con reintentos
- **WHEN** con `timeout=10`, `retries=2` y `deadline=15` un GET supera el timeout en el primer intento
- **THEN** el segundo intento usa como timeout lo que queda de los 15 s, y la llamada termina antes de 16 s con `isDeadlineExceeded()` igual a `true`

#### Scenario: Llamada exitosa dentro del tiempo
- **WHEN** un GET falla por error de conexión y el reintento responde 200 dentro del tiempo total
- **THEN** la llamada devuelve la respuesta 200

### Requirement: Tiempo total puntual
El tiempo total de una llamada SHALL poder fijarse con `->deadline($segundos)` antes del verbo, y MUST
mandar siempre: si es menor que el timeout, acorta el primer intento. Sin `->deadline()`, si el timeout
de la petición es mayor que el `deadline` configurado, MUST usarse el timeout como tiempo total, para que
el primer intento siempre quepa.

#### Scenario: Tiempo total fijado en la llamada
- **WHEN** se llama a `UboApi::call('api-maestros', $url)->deadline(5)->get(...)` y la API no responde
- **THEN** la llamada termina en unos 5 segundos

#### Scenario: Timeout puntual mayor que el tiempo total
- **WHEN** se llama a `->timeout(60)->get(...)` para una API con `deadline=30`
- **THEN** el primer intento puede durar hasta 60 segundos

### Requirement: Circuit breaker por API
Cada API llamada SHALL tener su propio circuito, identificado por su nombre en API-Auth y guardado en el
almacén de caché `ubo-apis.cache_store` (el por defecto si está vacío). Cuenta como fallo cada intento con
error de conexión, timeout o 5xx, en cualquier método; un intento con 2xx, 3xx o 4xx MUST cerrar el
circuito y reiniciar la cuenta. Tras `circuit_breaker.failures` fallos seguidos (5) el circuito MUST
abrirse durante `circuit_breaker.cooldown` segundos (30).

#### Scenario: API caída
- **WHEN** cinco intentos seguidos a `api-maestros` fallan por error de conexión y luego se hace otra llamada
- **THEN** se lanza `UboApiException` con `isCircuitOpen()` igual a `true` sin enviar la petición

#### Scenario: Otras APIs no se afectan
- **WHEN** el circuito de `api-maestros` está abierto y se llama a `api-matricula`
- **THEN** la llamada a `api-matricula` se envía normalmente

#### Scenario: Errores del cliente
- **WHEN** una API responde 404 o 422 muchas veces seguidas
- **THEN** su circuito sigue cerrado

#### Scenario: Circuito compartido entre workers
- **WHEN** el almacén `ubo-apis.cache_store` es compartido y un worker abre el circuito de una API
- **THEN** los demás workers que usan ese almacén tampoco envían peticiones a esa API

### Requirement: Llamadas a una API con el circuito abierto
Mientras el circuito de una API está abierto, sus llamadas SHALL lanzar `UboApiException` sin pedir token
ni enviar la petición. Pasado el enfriamiento, un solo proceso MUST hacer una llamada de prueba: si
funciona, el circuito se cierra; si falla, se abre por otro periodo completo. El circuito vive en la
aplicación que llama; la API llamada no se configura ni cambia.

#### Scenario: Recuperación
- **WHEN** pasa el enfriamiento y la llamada de prueba responde 200
- **THEN** el circuito se cierra y las siguientes llamadas se envían normalmente

#### Scenario: Prueba fallida
- **WHEN** pasa el enfriamiento y la llamada de prueba falla por error de conexión
- **THEN** el circuito se abre por otros `circuit_breaker.cooldown` segundos

#### Scenario: Tests con APIs simuladas
- **WHEN** un test llama a `UboApi::fake(...)`
- **THEN** todos los circuitos parten cerrados

### Requirement: Desactivar el circuito de una API
Con `circuit_breaker.enabled = false` en `defaults` o en `apis.<api>`, la API SHALL no tener circuito.

#### Scenario: Circuito desactivado para una API
- **WHEN** la configuración tiene `apis.api-legado.circuit_breaker.enabled = false`
- **THEN** las llamadas a `api-legado` siempre se envían, aunque fallen seguidas
