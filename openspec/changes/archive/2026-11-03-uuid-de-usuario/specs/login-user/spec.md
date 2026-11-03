# Spec Delta

## MODIFIED Requirements

### Requirement: Usuario autenticado
Con una sesión creada por el login, `Auth::guard('ubo')->user()` SHALL devolver un `UboUser` cuyo identificador
(`getAuthIdentifier()`, `id`) es el `uuid` de la persona, con `sub`, `roles`, `scopes`, `audiences` y `clientId`
del token. `hasRole($name)` SHALL ser verdadero solo con el nombre exacto, y `hasScope($scope)` con un scope que el
token tiene. `id()` y `check()` MUST reflejarlo.

#### Scenario: Datos del token
- **WHEN** una persona inició sesión con un token de `sub=42`, `uuid=9f1c…`, roles `["coordinador"]` y scope `geografia:leer`
- **THEN** el guard devuelve un usuario con id `9f1c…`, `sub` `42`, `hasRole('coordinador')` verdadero y `hasScope('geografia:leer')` verdadero

#### Scenario: Sin sesión
- **WHEN** una petición no tiene sesión del login
- **THEN** `check()` es falso y `user()` es `null`

### Requirement: Usuario simulado en pruebas
`UboLogin::actingAs()` SHALL dejar autenticada en la prueba a una persona con el id (su `uuid`), `sub` opcional,
roles, scopes y audiencias indicados, sin llamar a IAM ni pasar por el login, de modo que las rutas protegidas por
el módulo la vean como autenticada. En producción MUST fallar con `LogicException`.

#### Scenario: Ruta protegida en una prueba
- **WHEN** una prueba llama a `UboLogin::actingAs(id: '42', roles: ['coordinador'], scopes: ['geografia:leer'])` y pide una ruta con `ubo.login`
- **THEN** la ruta responde como a una persona autenticada con id `42`, ese rol y ese scope, sin ninguna llamada de red

#### Scenario: En producción
- **WHEN** se llama a `UboLogin::actingAs()` con `APP_ENV=production`
- **THEN** se lanza `LogicException`

## ADDED Requirements

### Requirement: Sesiones sin uuid
Una sesión cuya entrada de la bóveda no tiene `uuid` (abierta antes de este cambio) SHALL renovarse en la siguiente
consulta del guard, aunque falte para el margen, para obtenerlo del token renovado. Si no hay refresh token, o el
token renovado tampoco trae `uuid`, la sesión MUST terminar. El guard MUST NOT usar `sub` en lugar del `uuid`. Un
token renovado con un `uuid` distinto del de la sesión MUST terminarla.

#### Scenario: Sesión antigua con refresh token
- **WHEN** la bóveda tiene una sesión sin `uuid` con refresh token, e IAM renueva con un token que trae `uuid`
- **THEN** el guard devuelve el usuario con ese `uuid` y la bóveda lo guarda

#### Scenario: Sesión antigua sin refresh token
- **WHEN** la bóveda tiene una sesión sin `uuid` y sin refresh token
- **THEN** la sesión termina y la persona vuelve a pasar por IAM

#### Scenario: Token renovado de otra persona
- **WHEN** el token renovado trae un `uuid` distinto del de la sesión
- **THEN** la sesión termina
