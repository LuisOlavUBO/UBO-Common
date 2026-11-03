# Spec Delta

## RENAMED Requirements

- FROM: `### Requirement: Audiencia de los tokens M2M`
- TO: `### Requirement: Audiencia de los tokens`

## MODIFIED Requirements

### Requirement: Audiencia de los tokens
Si `UBO_AUTH_API` está definida, un token M2M o de usuario MUST incluir ese nombre en su `aud`, o se rechaza con
401 (`wrong_audience`) aunque tenga los scopes de la ruta. Sin `UBO_AUTH_API` no se revisa la audiencia.

#### Scenario: Token emitido para otra API
- **WHEN** `UBO_AUTH_API=api-maestros` y llega un token M2M con `aud = ["SIS", "api-matricula"]`
- **THEN** se rechaza con 401 y motivo `wrong_audience`

#### Scenario: API sin nombre configurado
- **WHEN** `UBO_AUTH_API` no está definida y llega un token M2M válido emitido para otra API
- **THEN** el token se acepta

#### Scenario: Token de usuario de otra API
- **WHEN** `UBO_AUTH_API=api-maestros`, se permiten tokens de usuario y llega uno sin `api-maestros` en `aud`
- **THEN** se rechaza con 401 y motivo `wrong_audience`

#### Scenario: Token de usuario para esta API
- **WHEN** `UBO_AUTH_API=api-maestros`, se permiten tokens de usuario y llega uno con `aud = ["<client id del monolito>", "api-maestros"]`
- **THEN** el token se acepta
