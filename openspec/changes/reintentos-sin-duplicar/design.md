# Design

## Context

La política de reintentos vive en el callback `when` de `PendingUboRequest::prepare()`, que Laravel invoca
con la excepción y el método HTTP, tanto en llamadas síncronas como asíncronas. Hoy trata todo
`ConnectionException` como transitorio. Laravel construye ese `ConnectionException` con la excepción de
Guzzle como `previous`, y el paquete admite dos versiones de Guzzle que la informan distinto:

- **Guzzle 8** separa la fase por clase: `ConnectException` (y su subclase `ConnectTimeoutException`) solo
  antes de establecer la conexión; `NetworkTimeoutException`, `NetworkException` o `ResponseException`
  después. No expone el `errno`.
- **Guzzle 7** usa `ConnectException` también para timeouts de transferencia y respuestas vacías, y expone
  `errno` y el mensaje de cURL en `getHandlerContext()`.

## Goals / Non-Goals

**Goals:**
- Reintentar POST y PATCH solo cuando es seguro que la petición no llegó a la API.
- Mantener sin cambios la política para los métodos idempotentes, los 5xx y los 401.

**Non-Goals:**
- Soporte de `Idempotency-Key`: requiere que cada API destino lo implemente.
- Deadline total, jitter o circuit breaker (hallazgo 3, otro cambio).

## Decisions

### Clasificar según la versión de Guzzle

La conexión **no** se estableció cuando la excepción de Guzzle es un `ConnectException` y:

- **Guzzle 8** (no tiene `getHandlerContext()`): siempre, porque Guzzle ya lo clasificó por fase.
- **Guzzle 7** (tiene `getHandlerContext()`): según el `errno` y el mensaje de cURL del contexto:

| `errno` | Significado | Mensaje |
|---|---|---|
| 6 | No se pudo resolver el host | cualquiera |
| 7 | No se pudo conectar (rechazada, sin ruta) | cualquiera |
| 28 | Timeout | solo si es de conexión o de resolución (`Connection timed out`, `Connection timeout`, `Resolving timed out`, `name lookup timed out`, `Failed to connect`) |
| 35 | Falló el handshake TLS | cualquiera (ningún byte HTTP se envió antes) |

En Guzzle 7 el código 28 se usa también para el timeout de la transferencia (`Operation timed out ...
bytes received`), por eso hace falta el mensaje. Cualquier otro caso (otra clase de excepción, otro `errno`
o un contexto sin `errno`) cuenta como "no se puede determinar" y POST/PATCH no se reintentan.

**Alternativas consideradas:**
- *Usar `on_stats` (TransferStats) para leer `connect_time`*: es más preciso, pero obliga a registrar un
  callback por petición y a compartir estado con el callback `when`. El mensaje de cURL es estable y basta.
- *No reintentar nunca POST/PATCH ante errores de conexión*: más simple, pero pierde el caso más común y
  seguro (la API se está reiniciando y rechaza la conexión).

### La clasificación es interna

La lógica queda en una clase `@internal` de ApiClient. No cambia ninguna interfaz pública ni la
configuración.

## Risks / Trade-offs

- [El mensaje de cURL cambia en otra versión de libcurl] → Si no coincide, el caso cae en "no se puede
  determinar" y no se reintenta: el error es por exceso de cautela, nunca una duplicación.
- [Peticiones asíncronas] → Usan el mismo `prepare()`, así que heredan la política. Se agrega un test asíncrono.
- [Tests que simulan errores] → `Http::failedConnection()` crea un `ConnectException` sin contexto, que
  Guzzle 8 lee como "no conectó" y Guzzle 7 como "no se puede determinar". Los tests nuevos construyen la
  excepción que produciría la versión instalada para cada caso, y prueban la rama de Guzzle 7 con una
  subclase de `ConnectException` que expone el contexto.
- [Solo una versión de Guzzle instalada a la vez] → La rama de la otra versión se prueba con esa subclase;
  la matriz de CI del cambio `herramientas-de-calidad` (`--prefer-lowest`) la ejecutará con Guzzle 7 real.
