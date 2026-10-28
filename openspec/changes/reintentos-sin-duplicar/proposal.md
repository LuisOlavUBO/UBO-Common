# Proposal

## Why

ApiClient reintenta cualquier método ante un error de conexión, y Laravel trata también como error de
conexión el timeout de lectura, cuando la API destino ya recibió la petición. Un POST o PATCH puede
ejecutarse hasta tres veces y duplicar operaciones (hallazgo 1 del diagnóstico de arquitectura). Hoy
ninguna API usa ApiClient, así que es el momento de corregirlo antes de que se adopte.

## What Changes

- Ante un error de conexión, POST y PATCH se reintentan solo si la conexión no llegó a establecerse
  (DNS, conexión rechazada, timeout de conexión, fallo del handshake TLS).
- Un timeout de lectura u otro error con la conexión ya establecida no se reintenta en POST ni PATCH: se
  lanza `UboApiException` de inmediato.
- Ante un error que no se puede clasificar, POST y PATCH no se reintentan.
- GET, HEAD, PUT, DELETE y OPTIONS se siguen reintentando ante cualquier error de conexión, como hoy.
- La renovación del token ante un 401 y los reintentos ante 5xx no cambian.
- No hay cambios **BREAKING**: se reduce la cantidad de reintentos en un caso que hoy es un defecto.

## Capabilities

### New Capabilities

Ninguna.

### Modified Capabilities

- `api-client`: el requisito "Reintento ante errores de conexión en cualquier método" pasa a distinguir
  según el método y la fase en que falló la conexión, y cambia de nombre.

## Impact

- Código: política de reintentos de ApiClient (`PendingUboRequest`), síncrona y asíncrona.
- Tests nuevos en `tests/ApiClient/` para POST con y sin conexión establecida, y GET con timeout de lectura.
- Documentación: paso 4 de "Qué hace la librería en cada llamada" en `docs/modules/api-client.md`, y
  comentario de `retries` en `config/ubo-apis.php`.
- Versión: 3.1.0 (minor). Sin efecto en API-Auth ni API-Maestros, que no usan ApiClient.
