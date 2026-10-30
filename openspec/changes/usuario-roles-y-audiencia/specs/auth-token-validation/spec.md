# Spec Delta

## MODIFIED Requirements

### Requirement: Token verificado disponible en la petición
Tras validar, el middleware SHALL dejar el token en la petición, accesible con
`VerifiedToken::from($request)`, que expone `id`, `clientId`, `userId`, `scopes`, `roles`, `expiresAt`,
`audiences`, `hasScope()`, `hasRole()` e `isMachineToken()`. Sin token validado, `from()` MUST devolver
`null`.

#### Scenario: Leer el token en el controlador
- **WHEN** un controlador detrás de `ubo.auth` llama a `VerifiedToken::from($request)`
- **THEN** obtiene el `clientId`, los `scopes` y los `roles` del token que llamó

#### Scenario: Ruta sin el middleware
- **WHEN** se llama a `VerifiedToken::from($request)` en una ruta sin `ubo.auth`
- **THEN** devuelve `null`

## ADDED Requirements

### Requirement: Roles del token
El token verificado SHALL llevar los nombres de rol de la claim `roles` del JWT, en el mismo orden. Un token sin
la claim, o con una que no es un arreglo, MUST tener `roles` vacío y no rechazarse. Los valores que no son texto
MUST descartarse. `hasRole($name)` SHALL ser verdadero solo si el nombre está en `roles`, con la misma
capitalización.

#### Scenario: Token con roles
- **WHEN** llega un token válido cuya claim `roles` es `["coordinador", "lector"]`
- **THEN** `roles` del token verificado es `["coordinador", "lector"]` y `hasRole('lector')` es verdadero

#### Scenario: Token sin la claim roles
- **WHEN** llega un token válido emitido sin la claim `roles`
- **THEN** se acepta, `roles` es una lista vacía y `hasRole('lector')` es falso

#### Scenario: Claim roles que no es un arreglo
- **WHEN** llega un token válido con `roles` igual a un texto
- **THEN** se acepta y `roles` es una lista vacía

#### Scenario: Rol con otra capitalización
- **WHEN** el token trae el rol `Lector` y se llama a `hasRole('lector')`
- **THEN** el resultado es falso

### Requirement: Roles en la introspección
Con las estrategias `introspection` e `hybrid`, los `roles` del token verificado SHALL salir del campo `roles` de
la respuesta de introspección (con `hybrid`, de la claim del JWT validado localmente). Un resultado en caché que
no tiene `roles`, guardado por una versión anterior, MUST leerse como lista vacía, sin llamar de nuevo a
API-Auth.

#### Scenario: Roles de un token introspeccionado
- **WHEN** con la estrategia `introspection` API-Auth responde activo con `roles` igual a `["vendedor"]`
- **THEN** el token verificado tiene `roles` igual a `["vendedor"]`

#### Scenario: Respuesta sin roles
- **WHEN** API-Auth responde activo sin el campo `roles`
- **THEN** el token se acepta y `roles` es una lista vacía

#### Scenario: Resultado en caché sin roles
- **WHEN** el resultado de un token está en caché, guardado sin el campo `roles`
- **THEN** se usa esa entrada con `roles` vacío y no se hace otra llamada de introspección
