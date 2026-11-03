# Spec Delta

## MODIFIED Requirements

### Requirement: Token de usuario de prueba
`forUser($userId, $clientId, $scopes, $ttl = 3600, $api = null, $roles = [], $audiences = null, $uuid = null)` SHALL
emitir un token con `sub` igual al usuario indicado, la claim `roles` con los roles indicados y la claim `uuid`: la
indicada o, sin ella, `TokenFactory::uuidFor($userId)`, un uuid determinístico derivado del usuario. Sin
`$audiences`, se emite para la API bajo prueba como `forClient`. Con `$audiences`, el `aud` del token es el client
id seguido de esas aplicaciones, como el de un token de usuario de API-Auth, y `$api` no se agrega.

#### Scenario: Token de usuario rechazado por defecto
- **WHEN** el test usa `forUser('12345', 'SIS', ['geografia:leer'])` sin permitir tokens de usuario
- **THEN** la ruta protegida responde 401

#### Scenario: Token de usuario con roles
- **WHEN** el test usa `forUser('12345', 'SIS', ['geografia:leer'], roles: ['coordinador'])` con tokens de usuario permitidos
- **THEN** el token verificado que ve la ruta tiene `roles` igual a `['coordinador']`

#### Scenario: Token de usuario con audiencias de varias aplicaciones
- **WHEN** el test usa `forUser('12345', 'SIS', [], audiences: ['api-maestros', 'portal'])`
- **THEN** el `aud` del token es `['SIS', 'api-maestros', 'portal']`

#### Scenario: uuid del token de usuario
- **WHEN** el test usa `forUser('12345', 'SIS')` y luego `forUser('12345', 'SIS', uuid: '0b2e…')`
- **THEN** el primer token tiene `uuid` igual a `TokenFactory::uuidFor('12345')` y el segundo `0b2e…`
