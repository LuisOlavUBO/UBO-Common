# Spec Delta

## MODIFIED Requirements

### Requirement: Canje de un código de autorización
Auth SHALL ofrecer canjear un código de autorización en `POST /api/auth/oauth/token` con
`grant_type=authorization_code`, el código, la `redirect_uri`, el `code_verifier` y las credenciales del cliente
que se le entreguen, y devolver el token de acceso con su vigencia y el refresh token. Las credenciales son las del
cliente de login, no las del cliente M2M de `ubo-auth`.

#### Scenario: Canje correcto
- **WHEN** se canjea un código y API-Auth responde 200 con `access_token`, `refresh_token` y `expires_in`
- **THEN** devuelve el token de acceso, el refresh token y la vigencia, y la petición llevó `grant_type`, `code`, `redirect_uri`, `code_verifier`, `client_id` y `client_secret`

#### Scenario: Respuesta sin refresh token
- **WHEN** API-Auth responde 200 con `access_token` y sin `refresh_token`
- **THEN** devuelve el token de acceso y no hay refresh token

#### Scenario: Credenciales distintas de las M2M
- **WHEN** se canjea un código con las credenciales de login estando definidas otras en `ubo-auth.client_id`
- **THEN** la petición lleva las de login

## ADDED Requirements

### Requirement: Renovación con el refresh token
Auth SHALL ofrecer renovar un token con `POST /api/auth/oauth/token`, `grant_type=refresh_token`, el refresh token y
las credenciales del cliente que se le entreguen, y devolver el nuevo token de acceso, el nuevo refresh token y la
vigencia. Un rechazo SHALL lanzar `UserTokenRejected` con el status y el código `error`; si API-Auth no responde o
su circuito está abierto, `AuthServerUnavailable`.

#### Scenario: Renovación correcta
- **WHEN** se renueva con un refresh token vigente y API-Auth responde 200 con tokens nuevos
- **THEN** devuelve el nuevo token de acceso, el nuevo refresh token y la vigencia, y la petición llevó `grant_type=refresh_token`, `refresh_token`, `client_id` y `client_secret`

#### Scenario: Refresh token ya usado
- **WHEN** API-Auth responde 400 con `error=invalid_grant`
- **THEN** se lanza `UserTokenRejected` con `status=400` y `error=invalid_grant`

#### Scenario: Secretos fuera del mensaje
- **WHEN** se lanza `UserTokenRejected` al renovar
- **THEN** su mensaje no contiene el refresh token ni el secreto del cliente

#### Scenario: Límite de solicitudes
- **WHEN** API-Auth responde 429
- **THEN** se lanza `UserTokenRejected` con `status=429`, y el circuito hacia API-Auth no se abre

#### Scenario: API-Auth no disponible
- **WHEN** el circuito hacia API-Auth está abierto o la renovación falla por error de conexión
- **THEN** se lanza `AuthServerUnavailable` y, con el circuito abierto, sin enviar la petición
