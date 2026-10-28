# auth-testing Specification

## Purpose

Permite que las aplicaciones prueben rutas protegidas por `ubo.auth` sin API-Auth levantado, con
tokens que pasan por los validadores reales.

## Requirements
### Requirement: Activar los tokens de prueba
`TokenFactory::fake()` SHALL configurar la clave pública de prueba como clave de API-Auth, definir una
URL de API-Auth de prueba si no hay una, y reemplazar la introspección por una lista de revocación en
memoria. La validación de firma, vigencia, tipo, audiencia y scopes MUST seguir siendo la real.

#### Scenario: Test sin API-Auth
- **WHEN** un test llama a `TokenFactory::fake()` y usa `forClient('SIS', ['geografia:leer'])` en una ruta que exige ese scope
- **THEN** la petición se acepta sin ninguna llamada de red

#### Scenario: Estrategia hybrid en tests
- **WHEN** la aplicación usa `hybrid` y el test llamó a `TokenFactory::fake()`
- **THEN** la introspección se resuelve con la lista de revocación de la fábrica, sin llamar a API-Auth

### Requirement: Token M2M de prueba
`forClient($clientId, $scopes, $ttl = 3600, $api = null)` SHALL emitir un token M2M firmado con la clave
de prueba, con `sub` igual al client id y emitido para la API bajo prueba (`ubo-auth.api`), salvo que se
indique otra API, o `''` para ninguna.

#### Scenario: Token de otra API
- **WHEN** `ubo-auth.api=api-maestros` y el test usa `forClient('SIS', ['geografia:leer'], api: 'api-matricula')`
- **THEN** la ruta protegida lo rechaza con 401 por audiencia

#### Scenario: Token sin API
- **WHEN** el test usa `forClient('SIS', [], api: '')`
- **THEN** el `aud` del token solo contiene el client id

### Requirement: Token de usuario de prueba
`forUser($userId, $clientId, $scopes, $ttl = 3600, $api = null)` SHALL emitir un token con `sub` igual al
usuario indicado, emitido para la API bajo prueba como `forClient`.

#### Scenario: Token de usuario rechazado por defecto
- **WHEN** el test usa `forUser('12345', 'SIS', ['geografia:leer'])` sin permitir tokens de usuario
- **THEN** la ruta protegida responde 401

### Requirement: Tokens inválidos de prueba
`expired($clientId, $scopes)` SHALL emitir un token vencido y `forged($clientId, $scopes)` un token
firmado con una clave que no es la de prueba, ambos emitidos para la API bajo prueba.

#### Scenario: Token vencido
- **WHEN** el test usa `expired('SIS')` en una ruta protegida
- **THEN** la respuesta es 401

#### Scenario: Token falsificado
- **WHEN** el test usa `forged('SIS')` en una ruta protegida
- **THEN** la respuesta es 401

### Requirement: Revocación simulada
`revoke($token)` SHALL marcar el token como revocado para las estrategias `introspection` e `hybrid`; con
`jwt` la revocación no se detecta, igual que en producción.

#### Scenario: Token revocado con hybrid
- **WHEN** el test revoca un token con `revoke()` y lo usa con la estrategia `hybrid`
- **THEN** la respuesta es 401

#### Scenario: Token revocado con jwt
- **WHEN** el test revoca un token con `revoke()` y lo usa con la estrategia `jwt`
- **THEN** el token se acepta

### Requirement: Claves de prueba distribuidas con el paquete
Las claves RSA de prueba SHALL distribuirse dentro del paquete y son públicas. No hay ninguna protección
que impida configurarlas fuera de los tests (comportamiento conocido, hallazgo 6).

#### Scenario: Clave de prueba en un entorno real
- **WHEN** un entorno configura `UBO_AUTH_PUBLIC_KEY` con la clave pública de prueba
- **THEN** acepta tokens firmados con la clave privada de prueba incluida en el paquete
