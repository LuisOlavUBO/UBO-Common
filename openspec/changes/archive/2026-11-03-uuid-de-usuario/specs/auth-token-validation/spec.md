# Spec Delta

## MODIFIED Requirements

### Requirement: Token verificado disponible en la petición
Tras validar, el middleware SHALL dejar el token en la petición, accesible con
`VerifiedToken::from($request)`, que expone `id`, `clientId`, `userId`, `uuid`, `scopes`, `roles`, `expiresAt`,
`audiences`, `hasScope()`, `hasRole()` e `isMachineToken()`. Sin token validado, `from()` MUST devolver
`null`.

#### Scenario: Leer el token en el controlador
- **WHEN** un controlador detrás de `ubo.auth` llama a `VerifiedToken::from($request)`
- **THEN** obtiene el `clientId`, los `scopes` y los `roles` del token que llamó

#### Scenario: Ruta sin el middleware
- **WHEN** se llama a `VerifiedToken::from($request)` en una ruta sin `ubo.auth`
- **THEN** devuelve `null`

## ADDED Requirements

### Requirement: Identificador público de la persona
`VerifiedToken::$uuid` SHALL ser el identificador público de la persona: la claim `uuid` del token con la
validación local, o el campo `uuid` de la introspección, en minúsculas. MUST ser `null` en un token de sistema y en
un token de usuario sin esa claim. `userId` MUST seguir siendo el `sub`.

#### Scenario: Token de usuario con uuid
- **WHEN** llega un token de usuario con `sub=42` y `uuid=9F1C…` (en mayúsculas)
- **THEN** `userId` es `42` y `uuid` es el mismo valor en minúsculas

#### Scenario: Token de usuario por introspección
- **WHEN** la introspección responde `uuid` para un token de usuario activo
- **THEN** `uuid` es ese valor en minúsculas

#### Scenario: Token de sistema
- **WHEN** llega un token M2M
- **THEN** `uuid` es `null`

#### Scenario: Token de usuario anterior a la claim
- **WHEN** llega un token de usuario sin claim `uuid`
- **THEN** el token se acepta y `uuid` es `null`
