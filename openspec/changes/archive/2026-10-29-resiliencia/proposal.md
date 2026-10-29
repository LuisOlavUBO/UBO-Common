# Proposal

## Why

Cuando API-Auth o una API UBO se cae o se pone lenta, la librería sigue esperando hasta el timeout
completo en cada petición y reintenta sin coordinarse. Hay dos consecuencias:

- Una API que valida tokens puede quedarse sin workers de PHP-FPM y caer completa por una falla de
  API-Auth (hallazgo 2 del diagnóstico de arquitectura).
- Una llamada con `UboApi` puede tardar hasta unos 40 s, y sus reintentos simultáneos castigan a la API
  que ya está con problemas (hallazgo 3).

Son los dos hallazgos de prioridad alta que siguen abiertos.

## What Changes

**Hacia API-Auth (módulo Auth), en toda app que use Common:**

- Timeout de conexión propio (`UBO_AUTH_CONNECT_TIMEOUT`, 2 s), separado del timeout total.
- Circuit breaker hacia API-Auth con estado en la caché compartida. Tras N fallos seguidos (conexión,
  timeout o 5xx), las llamadas fallan de inmediato durante un periodo de enfriamiento. La validación de
  tokens responde 503 sin esperar, y la obtención de tokens propios lanza `ServiceTokenException`. Pasado
  el enfriamiento, un solo intento de prueba decide si el circuito se cierra.
- Token propio de reserva: si renovar un token en caché falla porque API-Auth no responde, se sigue
  usando el token anterior mientras no haya expirado de verdad.
- Modo degradado **opcional y apagado por defecto** (`UBO_AUTH_DEGRADED_MODE`). Con la estrategia
  `hybrid`, si la introspección no responde, se aceptan los tokens que pasaron la validación local. Por
  defecto se sigue fallando cerrado (503).

**Hacia las APIs UBO (módulo ApiClient), en `UboApi::call()` y `UboApi::callAsync()`:**

- Tiempo total por llamada (`deadline`) que incluye los reintentos. Ningún intento ni espera lo supera.
- Espera entre reintentos exponencial con variación aleatoria (jitter) y un tope, en lugar de lineal.
- Circuit breaker por nombre de API con estado en la caché compartida. Mientras el circuito está
  abierto, la llamada lanza `UboApiException` sin enviar la petición.
- Valores por API en `ubo-apis.apis.<api>`, sobre los `defaults`.
- Tiempo total puntual en una llamada con `->deadline($segundos)`.

**Alcance:** el circuito siempre vive en la aplicación que llama, nunca en la API llamada. No cubre las
llamadas que una aplicación haga directamente con `Http::` o Guzzle, ni servicios externos a la UBO.

**Compatibilidad:** no hay cambios **BREAKING**. Todos los valores nuevos tienen un valor por defecto, y
la configuración publicada recibe las claves nuevas por el merge profundo. Cambian tres tiempos:

- La espera entre reintentos (de lineal a exponencial con jitter).
- El tiempo máximo de una llamada a una API UBO, que ahora tiene tope.
- El tiempo máximo para conectar con API-Auth.

## Capabilities

### New Capabilities

Ninguna. El circuit breaker es un comportamiento de cada capacidad que llama a otro servicio.

### Modified Capabilities

- `api-client`:
  - Valores por API.
  - Tiempo total por llamada.
  - Espera exponencial con jitter.
  - Circuit breaker por API.
  - Las llamadas asíncronas respetan el tiempo total de cada promesa.
- `auth-token-validation`:
  - Timeout de conexión hacia API-Auth.
  - Circuit breaker hacia API-Auth.
  - Modo degradado opcional en `hybrid`.
- `auth-service-token`:
  - Se usa el token en caché si la renovación falla porque API-Auth no responde.
  - Error inmediato con el circuito abierto.

## Impact

- **Código:**
  - `src/Auth`: `AuthSettings`, los proveedores HTTP de clave y token, los validadores y la configuración.
  - `src/ApiClient`: `ApiDefinition`, `PendingUboRequest`, `UboApiException` y la configuración.
  - `src/Support`: un circuit breaker sobre la caché, compartido por los dos módulos.
- **Configuración:** variables nuevas `UBO_AUTH_CONNECT_TIMEOUT`, `UBO_AUTH_CIRCUIT_FAILURES`,
  `UBO_AUTH_CIRCUIT_COOLDOWN` y `UBO_AUTH_DEGRADED_MODE`, y claves nuevas en `ubo-apis.php`
  (`deadline`, `retry_max_delay`, `circuit_breaker`, `cache_store`, `apis`).
- **Infraestructura:** para que el circuito sea compartido entre servidores, la app necesita un store de
  caché compartido (Redis o base de datos). Con `file` o `array`, cada servidor o proceso tiene su propio
  circuito. Sigue funcionando, pero detecta la caída más lento.
- **Tests:** nuevos en `tests/Auth`, `tests/ApiClient` y `tests/Support`.
- **Documentación:** `docs/modules/auth.md`, `docs/modules/api-client.md`, `.env.example` y el CHANGELOG.
- **Versión:** 3.1.0 (minor). Es independiente de los cambios en pausa `scopes-por-llamada` y
  `herramientas-de-calidad`.
