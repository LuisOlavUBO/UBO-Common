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
valores de `ubo-apis.defaults` (`timeout` 10 s, `connect_timeout` 5 s y `headers`) y el header
`traceparent` si el módulo `tracing` está activo. Se puede encadenar cualquier método de configuración
del cliente HTTP de Laravel antes del verbo.

#### Scenario: Header y timeout puntuales
- **WHEN** se llama a `->withHeaders(['If-None-Match' => $etag])->timeout(30)->get(...)`
- **THEN** la petición lleva ese header y usa 30 segundos de timeout

#### Scenario: Valores por defecto globales
- **WHEN** la aplicación cambia `ubo-apis.defaults.timeout`
- **THEN** el cambio se aplica a todas las APIs; no hay valores por API (comportamiento conocido, hallazgo 3)

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

### Requirement: Reintento ante errores de conexión en cualquier método
Ante un error de conexión, incluido un timeout de lectura, la petición SHALL reintentarse hasta `retries`
veces cualquiera sea el método, POST y PATCH incluidos. Nota: comportamiento conocido; puede duplicar
operaciones no idempotentes (hallazgo 1).

#### Scenario: POST con timeout de lectura
- **WHEN** un POST supera el timeout después de que la API recibió la petición
- **THEN** la petición se envía de nuevo, hasta `retries` veces

#### Scenario: API inalcanzable
- **WHEN** todos los intentos fallan por error de conexión
- **THEN** se lanza `UboApiException` con `status=null` e `isConnectionError()` igual a `true`

### Requirement: Espera entre reintentos
La espera antes de cada reintento SHALL ser `retry_delay` × número de intento milisegundos (200 por
defecto), lineal y sin variación aleatoria, y MUST ser cero en la renovación por 401. No hay un límite
total de tiempo por llamada (comportamiento conocido, hallazgo 3).

#### Scenario: Dos reintentos
- **WHEN** un GET falla por error de conexión tres veces con los valores por defecto
- **THEN** espera 200 ms antes del segundo intento y 400 ms antes del tercero

### Requirement: Excepción de llamada fallida
`UboApiException` SHALL exponer `api`, `status`, `errorCode` (campo `error`), `title`, `detail` (de
`detail`, `message` o `error_description`), `response` e `isConnectionError()`. Su mensaje MUST tener la
forma `[<api>] HTTP <status> <error>: <detalle>`.

#### Scenario: Mensaje de un problem
- **WHEN** `api-maestros` responde 404 con `error=NO_ENCONTRADO` y `detail='La comuna no existe.'`
- **THEN** el mensaje es `[api-maestros] HTTP 404 NO_ENCONTRADO: La comuna no existe.`

#### Scenario: Respuesta sin JSON
- **WHEN** la API responde 502 con un cuerpo HTML
- **THEN** se lanza `UboApiException` con `status=502` y `errorCode` nulo

### Requirement: Llamadas asíncronas
`UboApi::callAsync($api, $url)` SHALL ofrecer los mismos verbos, iniciar la petición de inmediato y
devolver un `UboPromise` cuyo `wait()` devuelve la respuesta o lanza `UboApiException`, con la misma
política de token y reintentos. Las promesas pendientes MUST avanzar en paralelo.

#### Scenario: Trabajo mientras la petición está en curso
- **WHEN** se crea una promesa con `callAsync(...)->get(...)` y luego se llama a `wait()`
- **THEN** `wait()` devuelve la respuesta 2xx de la API

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
