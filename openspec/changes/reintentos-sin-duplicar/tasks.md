# Tasks

## 1. Clasificación de errores de conexión

- [x] 1.1 Agregar en `tests/Pest.php` helpers que simulen, desde `Http::fake()`, una conexión rechazada, un timeout de conexión y un timeout de lectura con la excepción que produce la versión de Guzzle instalada, y verificar con un test que la `UboApiException` resultante conserva esa excepción de Guzzle como origen
- [x] 1.2 Crear una clase `@internal` de ApiClient que, a partir de un `ConnectionException`, determine si la conexión no llegó a establecerse según `design.md` (Guzzle 8: por clase; Guzzle 7: por `errno` 6, 7, 35, y 28 con mensaje de conexión o resolución), y verificarla con un test por cada caso: en Guzzle 8 `ConnectException`, `ConnectTimeoutException`, `NetworkTimeoutException` y `NetworkException`; en la rama de Guzzle 7 los `errno` 6, 7, 35, 28 de conexión, 28 de resolución, 28 `Operation timed out`, 52 y un contexto sin `errno`; y una excepción de Guzzle que no es `ConnectException`

## 2. Política de reintentos

- [x] 2.1 Cambiar el callback de reintento de `PendingUboRequest::prepare()` para que POST y PATCH se reintenten ante un error de conexión solo cuando la clase de 1.2 indique que no se conectó, y verificar en `tests/ApiClient/CallTest.php` los escenarios de la spec: POST con timeout de lectura (un solo envío, `UboApiException` con `isConnectionError()`), POST con conexión rechazada y luego 201 (devuelve 201), PATCH con timeout de conexión (se reintenta hasta `retries`) y POST con un error sin `errno` (un solo envío)
- [x] 2.2 Verificar en `tests/ApiClient/CallTest.php` que un GET con timeout de lectura y luego 200 devuelve 200, y que los tests existentes de reintentos (401, 5xx en GET, 500 en POST, fallos de conexión en GET) siguen pasando sin cambios
- [x] 2.3 Verificar en `tests/ApiClient/CallAsyncTest.php` que `callAsync` aplica la misma política: POST con timeout de lectura se envía una vez y POST con conexión rechazada se reintenta
- [x] 2.4 Actualizar el paso 4 de "Qué hace la librería en cada llamada" en `docs/modules/api-client.md` y el comentario de `retries` en `src/ApiClient/config/ubo-apis.php`, y verificar que describen la misma regla que el requisito "Reintento ante errores de conexión según el método"
- [x] 2.5 Agregar la entrada en `## [Unreleased]` de `CHANGELOG.md` bajo `### Fixed` (ApiClient ya no reintenta POST/PATCH cuando la conexión se estableció) y verificar que sigue el formato de las entradas anteriores

## 3. Verificación final

- [x] 3.1 Ejecutar la suite completa (`php vendor/bin/pest`) y `composer lint`, y verificar que todo pasa sin errores
- [x] 3.2 Ejecutar `openspec validate reintentos-sin-duplicar --strict` y verificar que no reporta errores

## Workflow follow-up

- Archivar el cambio con `/opsx:archive` cuando el PR esté mergeado, para que el requisito modificado pase a `openspec/specs/api-client/spec.md`.
- Publicar la versión 3.1.0 junto con los demás cambios de la 3.1.
