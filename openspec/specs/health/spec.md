# health Specification

## Purpose

Da a las APIs de la UBO un endpoint de salud común para orquestadores, balanceadores y healthchecks de
nginx, que informa si la base de datos responde.

## Requirements
### Requirement: Ruta registrada por la aplicación
El módulo SHALL ser pasivo y no registrar rutas. La aplicación MUST agregar la ruta con
`Route::get('v1/salud', HealthController::class)`, fuera de cualquier middleware de autenticación.

#### Scenario: API con prefijo propio
- **WHEN** la API publica sus rutas bajo `/api/maestros` y registra `v1/salud`
- **THEN** el endpoint responde en `GET /api/maestros/v1/salud` sin token

### Requirement: Respuesta con la base de datos disponible
Si la base de datos responde a `SELECT 1`, el endpoint SHALL responder 200 con
`{"estado": "OK", "base_datos": "OK"}`.

#### Scenario: Base de datos disponible
- **WHEN** se llama al endpoint y la base de datos responde
- **THEN** la respuesta es 200 con `{"estado":"OK","base_datos":"OK"}`

### Requirement: Respuesta con la base de datos caída
Si la consulta a la base de datos falla por cualquier motivo, el endpoint SHALL responder 503 con
`{"estado": "ERROR", "base_datos": "NO_DISPONIBLE"}`. El error MUST NOT exponerse en la respuesta y hoy
tampoco se registra en el log (comportamiento conocido, hallazgo 11).

#### Scenario: Base de datos caída
- **WHEN** se llama al endpoint y la conexión a la base de datos falla
- **THEN** la respuesta es 503 con `{"estado":"ERROR","base_datos":"NO_DISPONIBLE"}`

### Requirement: Conexión revisada
El chequeo SHALL usar la conexión `UBO_HEALTH_CONNECTION`, o la conexión por defecto de la aplicación si
está vacía. No hay un tiempo máximo propio para la consulta (comportamiento conocido, hallazgo 11).

#### Scenario: Conexión específica
- **WHEN** `UBO_HEALTH_CONNECTION=sqlsrv_maestros`
- **THEN** el `SELECT 1` se ejecuta en esa conexión

### Requirement: Contrato estable de la respuesta
La forma y los valores del cuerpo SHALL ser parte del contrato de las APIs: cambiarlos MUST tratarse como
un cambio MAJOR.

#### Scenario: Healthcheck de nginx
- **WHEN** un healthcheck lee `estado` del cuerpo
- **THEN** recibe `OK` o `ERROR`, sin otros valores

### Requirement: Simulación de la base de datos caída en tests
El chequeo de base de datos SHALL poder reemplazarse en los tests de la aplicación para simular que la
base de datos no responde.

#### Scenario: Mock del chequeo
- **WHEN** un test reemplaza el chequeo para que informe la base de datos no disponible
- **THEN** el endpoint responde 503
