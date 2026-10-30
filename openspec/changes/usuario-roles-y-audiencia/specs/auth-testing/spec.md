# Spec Delta

## MODIFIED Requirements

### Requirement: Token M2M de prueba
`forClient($clientId, $scopes, $ttl = 3600, $api = null, $roles = [])` SHALL emitir un token M2M firmado con la
clave de prueba, con `sub` igual al client id, la claim `roles` con los roles indicados y emitido para la API
bajo prueba (`ubo-auth.api`), salvo que se indique otra API, o `''` para ninguna.

#### Scenario: Token de otra API
- **WHEN** `ubo-auth.api=api-maestros` y el test usa `forClient('SIS', ['geografia:leer'], api: 'api-matricula')`
- **THEN** la ruta protegida lo rechaza con 401 por audiencia

#### Scenario: Token sin API
- **WHEN** el test usa `forClient('SIS', [], api: '')`
- **THEN** el `aud` del token solo contiene el client id

#### Scenario: Token M2M con roles
- **WHEN** el test usa `forClient('SIS', ['geografia:leer'], roles: ['lector'])` en una ruta protegida
- **THEN** el token verificado que ve la ruta tiene `roles` igual a `['lector']`

### Requirement: Token de usuario de prueba
`forUser($userId, $clientId, $scopes, $ttl = 3600, $api = null, $roles = [], $audiences = null)` SHALL emitir un
token con `sub` igual al usuario indicado y la claim `roles` con los roles indicados. Sin `$audiences`, se emite
para la API bajo prueba como `forClient`. Con `$audiences`, el `aud` del token es el client id seguido de esas
aplicaciones, como el de un token de usuario de API-Auth, y `$api` no se agrega.

#### Scenario: Token de usuario rechazado por defecto
- **WHEN** el test usa `forUser('12345', 'SIS', ['geografia:leer'])` sin permitir tokens de usuario
- **THEN** la ruta protegida responde 401

#### Scenario: Token de usuario con roles
- **WHEN** el test usa `forUser('12345', 'SIS', ['geografia:leer'], roles: ['coordinador'])` con tokens de usuario permitidos
- **THEN** el token verificado que ve la ruta tiene `roles` igual a `['coordinador']`

#### Scenario: Token de usuario con audiencias de varias aplicaciones
- **WHEN** el test usa `forUser('12345', 'SIS', [], audiences: ['api-maestros', 'portal'])`
- **THEN** el `aud` del token es `['SIS', 'api-maestros', 'portal']`
