# api-client Specification

## Purpose

Permite que una aplicación llame a cualquier API interna de la UBO indicando solo su nombre en
API-Auth, su URL y el endpoint: la librería obtiene el token, reintenta y convierte los errores.

## Requirements

### Requirement: Llamada síncrona a una API
`UboApi::call($api, $url)` SHALL devolver un cliente con los verbos `get`, `head` (con query opcional),
`post`, `put`, `patch` y `delete` (con datos JSON). Cada verbo MUST devolver la respuesta HTTP si es 2xx
o 3xx, y lanzar `UboApiException` si es 4xx o 5xx.

#### Scenario: GET exitoso
- **WHEN** se llama a `UboApi::call('api-maestros', $url)->get('/api/maestros/v1/geografia/paises')` y la API responde 200
- **THEN** se devuelve la respuesta, de la que se puede leer `json('data')`

#### Scenario: Recurso inexistente
- **WHEN** la API responde 404 con un problem RFC 9457 `{"error": "NO_ENCONTRADO"}`
- **THEN** se lanza `UboApiException` con `status=404` y `errorCode='NO_ENCONTRADO'`

### Requirement: Nombre y URL obligatorios
El nombre de la API y su URL MUST ser no vacíos; si falta alguno, la llamada MUST fallar con
`InvalidArgumentException` antes de hacer ninguna petición. La URL de cada API la guarda la aplicación
que llama; la librería no tiene un catálogo de APIs.

#### Scenario: Variable de URL sin definir
- **WHEN** se llama a `UboApi::call('api-maestros', null)`
- **THEN** se lanza `InvalidArgumentException` que indica revisar la variable del `.env`

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

### Requirement: Token por API obtenido automáticamente
Cada llamada SHALL llevar `Authorization: Bearer` con un token propio emitido para esa API, sin scopes
explícitos, de modo que trae todos los scopes que los roles del sistema tienen ahí (comportamiento
conocido, hallazgo 5). Si no se obtiene el token, MUST lanzarse `UboApiException` sin llamar a la API.

#### Scenario: Sistema sin permisos en la API
- **WHEN** API-Auth rechaza el token para `api-maestros`
- **THEN** se lanza `UboApiException` que indica que no se pudo obtener el token, sin llamar a la API

### Requirement: Renovación del token ante 401
Si la API responde 401, el token de esa API SHALL descartarse, pedirse uno nuevo y la petición
reintentarse una vez de inmediato, además de los reintentos configurados. Un segundo 401 MUST lanzar
`UboApiException`.

#### Scenario: Token revocado en la API destino
- **WHEN** la API responde 401 y luego 200 con el token nuevo
- **THEN** la llamada devuelve la respuesta 200

### Requirement: Reintento ante errores de servidor en métodos idempotentes
Ante un 5xx, la petición SHALL reintentarse hasta `retries` veces (2 por defecto) solo si el método es
GET, HEAD, PUT, DELETE u OPTIONS. POST y PATCH MUST NOT reintentarse ante un 5xx.

#### Scenario: GET con 503 transitorio
- **WHEN** un GET recibe 503 y luego 200
- **THEN** la llamada devuelve la respuesta 200

#### Scenario: POST con 500
- **WHEN** un POST recibe 500
- **THEN** se lanza `UboApiException` sin reintentar

### Requirement: Reintento ante errores de conexión según el método
Ante un error de conexión, GET, HEAD, PUT, DELETE y OPTIONS SHALL reintentarse hasta `retries` veces. POST
y PATCH MUST reintentarse solo si la conexión con la API no llegó a establecerse; si se estableció (por
ejemplo, un timeout de lectura) o no se puede determinar, MUST lanzarse `UboApiException` sin reintentar.

#### Scenario: POST con timeout de lectura
- **WHEN** un POST supera el timeout después de que la API recibió la petición
- **THEN** se lanza `UboApiException` con `isConnectionError()` igual a `true` y la petición se envía una sola vez

#### Scenario: POST sin poder conectar
- **WHEN** un POST falla porque la conexión con la API fue rechazada y el siguiente intento responde 201
- **THEN** la llamada devuelve la respuesta 201

#### Scenario: PATCH con timeout de conexión
- **WHEN** un PATCH falla porque se agotó el tiempo para establecer la conexión
- **THEN** la petición se reintenta, hasta `retries` veces

#### Scenario: POST con un error de conexión sin clasificar
- **WHEN** un POST falla con un error de conexión que no indica en qué fase ocurrió
- **THEN** se lanza `UboApiException` sin reintentar

#### Scenario: GET con timeout de lectura
- **WHEN** un GET supera el timeout de lectura y el siguiente intento responde 200
- **THEN** la llamada devuelve la respuesta 200

#### Scenario: API inalcanzable
- **WHEN** todos los intentos fallan por error de conexión
- **THEN** se lanza `UboApiException` con `status=null` e `isConnectionError()` igual a `true`

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

### Requirement: Esperar varias llamadas en paralelo
`UboApi::all($promesas)` SHALL esperar todas las promesas y devolver sus respuestas con las mismas
claves. Si alguna falla, MUST lanzar la `UboApiException` de la primera que falle en el orden del arreglo.

#### Scenario: Dos llamadas en paralelo
- **WHEN** se llama a `all(['paises' => $p1, 'comunas' => $p2])` y ambas responden 200
- **THEN** se devuelve `['paises' => <respuesta>, 'comunas' => <respuesta>]` y el tiempo total es el de la más lenta

### Requirement: Simulación de APIs en tests
`UboApi::fake($stubs)` SHALL simular el endpoint de token de API-Auth y las APIs indicadas, sin
configuración previa. Una clave `<api>/<ruta>` MUST dirigir toda llamada a esa API a un host falso,
cualquiera sea la URL recibida; una clave `http(s)://` se usa tal cual.

#### Scenario: API simulada sin URL configurada
- **WHEN** un test llama a `UboApi::fake(['api-maestros/api/maestros/v1/geografia/*' => ['data' => []]])` y luego a `call('api-maestros', null)->get('/api/maestros/v1/geografia/paises')`
- **THEN** la llamada devuelve 200 con `{"data": []}`

#### Scenario: Aserciones de Laravel
- **WHEN** un test usa `UboApi::fake()` y hace una llamada
- **THEN** puede verificarla con `Http::assertSent(...)`

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
