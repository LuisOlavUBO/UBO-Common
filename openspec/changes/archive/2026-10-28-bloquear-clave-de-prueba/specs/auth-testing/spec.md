# Spec Delta

## MODIFIED Requirements

### Requirement: Claves de prueba distribuidas con el paquete
Las claves RSA de prueba SHALL distribuirse dentro del paquete y son públicas. Con `APP_ENV=production`, si
la clave pública para validar (fijada o descargada) es la de prueba, la validación MUST fallar cerrada con
`AuthServerUnavailable` (503), registrar el motivo en el log y no aceptar ningún token.

#### Scenario: Clave de prueba en un entorno real
- **WHEN** con `APP_ENV=production` se configura `UBO_AUTH_PUBLIC_KEY` con la clave pública de prueba y llega un token firmado con la clave privada de prueba
- **THEN** la respuesta es 503 y la petición no llega al controlador

#### Scenario: API-Auth firmando con la clave de prueba
- **WHEN** con `APP_ENV=production` la clave descargada de API-Auth es la clave pública de prueba
- **THEN** ningún token se acepta y el motivo queda en el log

#### Scenario: Clave de prueba con otro formato
- **WHEN** con `APP_ENV=production` la clave de prueba se configura con otros saltos de línea o espacios al final
- **THEN** se reconoce igual como la clave de prueba y se rechaza

#### Scenario: Clave de prueba fuera de producción
- **WHEN** con `APP_ENV=testing` o `APP_ENV=local` se usa la clave pública de prueba
- **THEN** los tokens firmados con la clave privada de prueba se aceptan como hoy

### Requirement: Activar los tokens de prueba
`TokenFactory::fake()` SHALL configurar la clave pública de prueba como clave de API-Auth, definir una
URL de API-Auth de prueba si no hay una, y reemplazar la introspección por una lista de revocación en
memoria. La validación de firma, vigencia, tipo, audiencia y scopes MUST seguir siendo la real. Con
`APP_ENV=production`, `fake()` MUST fallar con `LogicException` sin cambiar la configuración.

#### Scenario: Test sin API-Auth
- **WHEN** un test llama a `TokenFactory::fake()` y usa `forClient('SIS', ['geografia:leer'])` en una ruta que exige ese scope
- **THEN** la petición se acepta sin ninguna llamada de red

#### Scenario: Estrategia hybrid en tests
- **WHEN** la aplicación usa `hybrid` y el test llamó a `TokenFactory::fake()`
- **THEN** la introspección se resuelve con la lista de revocación de la fábrica, sin llamar a API-Auth

#### Scenario: fake() en producción
- **WHEN** se llama a `TokenFactory::fake()` con `APP_ENV=production`
- **THEN** se lanza `LogicException` y la clave pública configurada no cambia
