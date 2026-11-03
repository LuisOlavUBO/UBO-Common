# Spec Delta

## ADDED Requirements

### Requirement: Token de la persona para llamar a otras APIs
El módulo SHALL ofrecer el contrato `UserTokenProvider` con el token de acceso vigente de la persona que tiene
sesión en la petición actual. La persona MUST resolverse como lo hace el guard `ubo`, renovando el token si
corresponde, sin repetir en la misma petición una consulta a IAM que el guard ya dejó sin resolver. Sin una
persona con sesión MUST lanzar `AuthenticationException`.

#### Scenario: Persona con sesión
- **WHEN** una persona tiene sesión con un token vigente y se pide su token
- **THEN** se devuelve el token de acceso que guarda la bóveda

#### Scenario: Token por renovar
- **WHEN** al token de la persona le quedan menos de `UBO_LOGIN_REFRESH_MARGIN` segundos y se pide su token
- **THEN** se renueva con el refresh token y se devuelve el token nuevo

#### Scenario: Sin sesión
- **WHEN** se pide el token en una petición sin sesión del login
- **THEN** se lanza `AuthenticationException`

### Requirement: Renovación del token tras un rechazo
Cuando una API rechazó un token de la persona, el contrato SHALL devolver, a partir del token rechazado, el token
vigente de la bóveda si es distinto; si es el mismo, MUST renovarlo con el refresh token sin esperar el margen,
una sola vez aunque lleguen peticiones en paralelo, y devolver el nuevo. Si no hay refresh token o IAM no lo
renueva, MUST devolver `null`. Un `invalid_grant` MUST tratarse como en la renovación normal.

#### Scenario: Otra petición ya renovó
- **WHEN** se informa el rechazo de un token que la bóveda ya reemplazó
- **THEN** se devuelve el token de la bóveda sin llamar a IAM

#### Scenario: Renovación forzada
- **WHEN** se informa el rechazo del token que la bóveda tiene, con un refresh token
- **THEN** se renueva en IAM aunque falte para el margen y se devuelve el token nuevo

#### Scenario: Sin refresh token
- **WHEN** se informa el rechazo del token de una persona simulada o sin refresh token
- **THEN** se devuelve `null` sin llamar a IAM
