# Spec Delta

## MODIFIED Requirements

### Requirement: Token por API obtenido automáticamente
Cada llamada SHALL llevar `Authorization: Bearer` con un token propio emitido para esa API. Sin
`->scopes([...])`, el token se pide sin scopes y trae todos los del rol del sistema en esa API; con
`->scopes([...])`, MUST pedirse solo con esos scopes. Si no se obtiene el token, MUST lanzarse
`UboApiException` sin llamar a la API.

#### Scenario: Sistema sin permisos en la API
- **WHEN** API-Auth rechaza el token para `api-maestros`
- **THEN** se lanza `UboApiException` que indica que no se pudo obtener el token, sin llamar a la API

#### Scenario: Llamada sin scopes explícitos
- **WHEN** se llama a `UboApi::call('api-maestros', $url)->get(...)`
- **THEN** el token se pide con `api=api-maestros` y sin `scope`

#### Scenario: Llamada con scopes explícitos
- **WHEN** se llama a `UboApi::call('api-maestros', $url)->scopes(['geografia:leer'])->get(...)`
- **THEN** el token se pide con `api=api-maestros` y `scope=geografia:leer`

#### Scenario: Scope no permitido
- **WHEN** se pide `->scopes(['sistemas:escribir'])` y el rol del sistema no lo tiene en esa API
- **THEN** se lanza `UboApiException` sin llamar a la API

#### Scenario: Tokens en caché por combinación de scopes
- **WHEN** se llama a la misma API con `->scopes(['a'])` y luego sin scopes
- **THEN** cada llamada usa su propio token y ninguno reemplaza al otro en caché

#### Scenario: Renovación ante 401 con scopes
- **WHEN** una llamada con `->scopes(['geografia:leer'])` recibe 401
- **THEN** se descarta y se renueva el token de esa API con `geografia:leer`, y el token sin scopes en caché no se toca

#### Scenario: Llamada asíncrona con scopes
- **WHEN** se llama a `UboApi::callAsync('api-maestros', $url)->scopes(['geografia:leer'])->get(...)`
- **THEN** el token se pide con `scope=geografia:leer`
