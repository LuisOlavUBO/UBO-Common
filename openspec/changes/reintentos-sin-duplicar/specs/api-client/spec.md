# Spec Delta

## RENAMED Requirements

- FROM: `### Requirement: Reintento ante errores de conexión en cualquier método`
- TO: `### Requirement: Reintento ante errores de conexión según el método`

## MODIFIED Requirements

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
