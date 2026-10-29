# Tasks

## 1. Base compartida

- [x] 1.1 Mover `positiveInt`, `nonNegativeInt` y `filledString` de `AuthSettings` a `Support\ConfigValue` (`@internal`) y hacer que `AuthSettings` las use; verificar que `tests/Auth/AuthSettingsTest.php` sigue pasando sin cambios y agregar `tests/Support/ConfigValueTest.php` con valores vacíos, no numéricos, cero y negativos
- [x] 1.2 Crear `Support\CircuitBreaker` según la decisión 1 de `design.md`, con las claves `failures`, `open` y `probe` y los métodos `allowsRequest()`, `recordSuccess()` y `recordFailure()`. Verificar en `tests/Support/CircuitBreakerTest.php`, con `travel()`, que: se abre tras N fallos; sigue cerrado con un éxito entre medio; deja pasar una sola prueba tras el enfriamiento; se cierra si la prueba funciona; se vuelve a abrir si falla; y el contador expira tras `cooldown` sin fallos
- [x] 1.3 Hacer que `CircuitBreaker` trate como cerrado el circuito ante cualquier excepción del almacén y registre las transiciones una sola vez (`warning` al abrir, `info` al cerrar); verificar con un `Repository` que lanza excepciones y con `Log::spy()` que dos fallos con el circuito ya abierto no generan un segundo `warning`
- [x] 1.4 Agregar `CircuitBreaker::middleware()` (middleware de Guzzle: éxito ante 2xx, 3xx y 4xx; fallo ante 5xx y excepción de red) y verificar con un test que, aplicado con `withMiddleware()` sobre `Http::fake()` (respuestas 200, 404, 503 y `ConnectionException` simulada), registra el resultado de cada intento; si no corre con respuestas simuladas, detenerse y revisar la decisión 2 antes de seguir

## 2. Llamadas a API-Auth

- [x] 2.1 Agregar a `AuthSettings` y a `src/Auth/config/ubo-auth.php` `connect_timeout` (`UBO_AUTH_CONNECT_TIMEOUT`, 2), `circuit_breaker.failures` y `circuit_breaker.cooldown` (`UBO_AUTH_CIRCUIT_FAILURES` y `UBO_AUTH_CIRCUIT_COOLDOWN`, 5 y 30) y `degraded_mode` (`UBO_AUTH_DEGRADED_MODE`, `false`); verificar en `tests/Auth/AuthSettingsTest.php` los valores vacíos, cero y faltantes, y en `tests/Support/PublishedConfigTest.php` que una `ubo-auth.php` publicada de la 3.0.0 recibe los valores por defecto
- [x] 2.2 Crear `Auth\Infrastructure\ApiAuthClient` (decisión 3): petición base con `connectTimeout` (limitado a `timeout`), `timeout` y `traceparent`, circuito `api-auth` sobre el almacén de Auth, excepción interna `ApiAuthUnreachable` para el circuito abierto, errores de red y 5xx, y `withoutCircuit()`. Verificar en `tests/Auth/ApiAuthClientTest.php` que: con el circuito abierto no se envía nada (`Http::assertNothingSent()`); un 4xx no cuenta como fallo; y `withoutCircuit()` llama aunque el circuito esté abierto y no cambia su estado
- [x] 2.3 Hacer que `HttpPublicKeyProvider`, `IntrospectionValidator` y `HttpServiceTokenProvider` usen `ApiAuthClient` y traduzcan `ApiAuthUnreachable` a `AuthServerUnavailable` o `ServiceTokenException`; verificar que pasan todos los tests existentes de `tests/Auth` y agregar en `tests/Auth/IntrospectionTest.php` los escenarios "API-Auth caído" (503 sin llamar a API-Auth tras 5 timeouts), "Recuperación", "Prueba fallida", "Errores del cliente no abren el circuito" y "Tokens que no necesitan a API-Auth"
- [x] 2.4 Verificar en `tests/Auth/IntrospectionTest.php` que el timeout de conexión se aplica (una conexión rechazada o con timeout de conexión falla sin esperar `UBO_AUTH_TIMEOUT`, usando los helpers de `tests/Pest.php`) y que el mensaje de `AuthServerUnavailable` con el circuito abierto dice "circuit open"
- [x] 2.5 Cambiar `ubo:auth:check` para que use `ApiAuthClient::withoutCircuit()` y muestre el estado del circuito y si el modo degradado está activo; verificar en `tests/Auth/CheckCommandTest.php` el escenario "Circuito abierto"

## 3. Token propio de reserva

- [x] 3.1 Cambiar la entrada en caché de `HttpServiceTokenProvider` a `['token', 'refresh_at', 'expires_at']` con TTL `expires_in`, aceptando como vigentes las entradas antiguas (string), con el reloj de `ClockInterface`; verificar en `tests/Auth/ServiceTokenTest.php` "Reutilización", "Renovación anticipada" y que una entrada string sigue sirviendo
- [x] 3.2 Devolver el token en caché no expirado cuando la renovación lanza `ApiAuthUnreachable` (error de red, 5xx o circuito abierto) y lanzar la excepción ante un 4xx o un token expirado; verificar en `tests/Auth/ServiceTokenTest.php` los escenarios "API-Auth caído durante la renovación", "Token ya expirado", "Credenciales revocadas" y "Circuito abierto sin token en caché"
- [x] 3.3 Documentar en `docs/modules/auth.md` el timeout de conexión, el circuito hacia API-Auth (incluida la recomendación de un almacén compartido) y el token de reserva, agregar las variables nuevas a `.env.example`, y verificar que los nombres y valores por defecto coinciden con `src/Auth/config/ubo-auth.php`

## 4. Modo degradado

- [x] 4.1 Hacer que `HybridValidator` devuelva el token local cuando la validación remota lanza `AuthServerUnavailable` y `degradedMode` está activo, con un `warning` como máximo una vez por minuto, y que `AuthServiceProvider` le pase la configuración y el logger; verificar en `tests/Auth/IntrospectionTest.php` los escenarios "API-Auth caído con modo degradado", "Token revocado con API-Auth disponible", "Token falsificado con modo degradado", "Estrategia introspection" e "Introspección caída" (con el modo apagado, 503)
- [x] 4.2 Documentar en `docs/modules/auth.md` el modo degradado, su riesgo (se aceptan tokens revocados mientras dura la caída) y que viene apagado; verificar que el texto coincide con el requisito "Modo degradado en la estrategia híbrida"

## 5. ApiClient: valores por API, tiempo total y espera

- [x] 5.1 Agregar a `ApiDefinition` y a `src/ApiClient/config/ubo-apis.php` `deadline` (30), `retry_max_delay` (2000), `circuit_breaker` (`enabled`, `failures`, `cooldown`), `cache_store` y la sección `apis`, con la resolución por clave de la decisión 7; verificar en un nuevo `tests/ApiClient/ApiDefinitionTest.php` los escenarios "API con valores propios", "Valor parcial" y "Configuración publicada de la versión 3.0.0"
- [x] 5.2 Cambiar la espera de `PendingUboRequest` a exponencial con jitter y tope (decisión 6); verificar en `tests/ApiClient/CallTest.php`, con `Sleep::fake()`, los rangos de "Dos reintentos", "Tope de la espera" y que la renovación por 401 no espera
- [x] 5.3 Implementar el tiempo total por llamada y `->deadline()` (decisión 6) con `UboApiException::deadlineExceeded()` e `isDeadlineExceeded()`; verificar en `tests/ApiClient/CallTest.php` "API lenta con reintentos", "Tiempo total fijado en la llamada", "Timeout puntual mayor que el tiempo total" y "Llamada exitosa dentro del tiempo", comprobando el timeout de cada intento con `Http::assertSent()` o con el reloj falso
- [x] 5.4 Verificar en `tests/ApiClient/CallAsyncTest.php` "Tiempo total en llamadas paralelas" (dos promesas que fallan terminan en `deadline + 1 s` como máximo) y que los tests asíncronos existentes siguen pasando

## 6. ApiClient: circuit breaker por API

- [x] 6.1 Crear en `ApiClientManager` los circuitos por nombre de API (almacén `ubo-apis.cache_store`, prefijo `ubo-apis:circuit:`), consultarlos en `PendingUboRequest` antes de pedir el token y agregar su middleware; lanzar `UboApiException::circuitOpen()` con `isCircuitOpen()` en síncrono y devolver un `UboPromise` rechazado en asíncrono. Verificar en `tests/ApiClient/CallTest.php` y `CallAsyncTest.php` "API caída", "Otras APIs no se afectan", "Recuperación", "Prueba fallida", "Errores del cliente", "Circuito abierto en una llamada asíncrona" y que con el circuito abierto no se pide token (`Http::assertNotSent` al endpoint de token)
- [x] 6.2 Respetar `circuit_breaker.enabled = false` en `defaults` y en `apis.<api>`, y verificar el escenario "Circuito desactivado para una API"
- [x] 6.3 Hacer que `UboApi::fake()` reemplace el almacén de los circuitos por un `ArrayStore` nuevo (decisión 8); verificar en `tests/ApiClient/FakeTest.php` el escenario "Tests con APIs simuladas" abriendo un circuito en un test y comprobando que tras `fake()` la llamada se envía
- [x] 6.4 Actualizar `docs/modules/api-client.md` (valores por API, tiempo total, espera, circuito, `isCircuitOpen()` e `isDeadlineExceeded()`, almacén compartido y que no cubre `Http::` directo) y el comentario de `ubo-apis.php`; verificar que los valores por defecto documentados coinciden con los del archivo de configuración

## 7. Cierre

- [x] 7.1 Agregar en `## [Unreleased]` de `CHANGELOG.md` las entradas `### Added` (circuitos, tiempo total, valores por API, token de reserva, modo degradado) y `### Changed` (espera exponencial con jitter, timeout de conexión hacia API-Auth), y verificar que siguen el formato de las entradas anteriores
- [x] 7.2 Ejecutar la suite completa (`php vendor/bin/pest`) y `composer lint`, y verificar que todo pasa
- [x] 7.3 Ejecutar `openspec validate resiliencia --strict` y verificar que no reporta errores

## Workflow follow-up

- Archivar el cambio con `/opsx:archive` cuando el PR esté mergeado, para que los requisitos pasen a `openspec/specs/`.
- Publicar la versión 3.1.0 junto con los demás cambios de la 3.1.
