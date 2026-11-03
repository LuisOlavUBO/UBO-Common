# Spec Delta

## MODIFIED Requirements

### Requirement: Validación del token recibido
El token de acceso recibido SHALL validarse localmente (firma con la clave pública de IAM y vigencia) antes de
crear la sesión, y MUST haber sido emitido al cliente de login, a una persona (no a un cliente de sistema) y traer
la claim `uuid`. Un token inválido SHALL lanzar `LoginFailed` (400). La validación local no usa credenciales M2M
ni consulta la introspección.

#### Scenario: Token firmado con otra clave
- **WHEN** IAM responde un token firmado con una clave que no es la suya
- **THEN** se lanza `LoginFailed` y no se crea la sesión

#### Scenario: Token de otro cliente
- **WHEN** el token recibido tiene como primera audiencia un client id distinto del de login
- **THEN** se lanza `LoginFailed` y no se crea la sesión

#### Scenario: Token sin uuid
- **WHEN** IAM responde un token de persona sin claim `uuid` (una versión de API-Auth anterior a esa claim)
- **THEN** se lanza `LoginFailed` que indica actualizar API-Auth, y no se crea la sesión

#### Scenario: Sin credenciales M2M
- **WHEN** la aplicación no tiene `UBO_AUTH_CLIENT_ID` ni `UBO_AUTH_CLIENT_SECRET`
- **THEN** el login funciona igual, sin llamadas de introspección
