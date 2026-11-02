# Spec Delta

## Purpose

Permite que una aplicación obtenga de API-Auth los tokens de una persona (canjeando el código de autorización) y
los revoque, con los mismos timeouts, circuito y traza que el resto de las llamadas a API-Auth.

## ADDED Requirements

### Requirement: Canje de un código de autorización
Auth SHALL ofrecer canjear un código de autorización en `POST /api/auth/oauth/token` con
`grant_type=authorization_code`, el código, la `redirect_uri`, el `code_verifier` y las credenciales del cliente
que se le entreguen, y devolver el token de acceso con su vigencia. Las credenciales son las del cliente de login,
no las del cliente M2M de `ubo-auth`.

#### Scenario: Canje correcto
- **WHEN** se canjea un código y API-Auth responde 200 con `access_token` y `expires_in`
- **THEN** devuelve el token de acceso y su vigencia, y la petición llevó `grant_type`, `code`, `redirect_uri`, `code_verifier`, `client_id` y `client_secret`

#### Scenario: Credenciales distintas de las M2M
- **WHEN** se canjea un código con las credenciales de login estando definidas otras en `ubo-auth.client_id`
- **THEN** la petición lleva las de login

### Requirement: Rechazo del canje
Si API-Auth responde 4xx o una respuesta sin `access_token`, Auth SHALL lanzar `UserTokenRejected` con el status, el
código `error` y la descripción (`error_description` o `message`, y `hint`). El mensaje MUST NOT incluir el código,
el verificador ni el secreto.

#### Scenario: Código ya usado
- **WHEN** API-Auth responde 400 con `error=invalid_grant`
- **THEN** se lanza `UserTokenRejected` con `status=400` y `error=invalid_grant`

#### Scenario: Secretos fuera del mensaje
- **WHEN** se lanza `UserTokenRejected`
- **THEN** su mensaje no contiene el secreto del cliente ni el `code_verifier`

### Requirement: Revocación del token de una persona
Auth SHALL ofrecer revocar un token de acceso con `POST /api/auth/v1/logout` y ese token como Bearer, lo que
revoca también su refresh token. Un 401 (token ya revocado o vencido) MUST tratarse como éxito.

#### Scenario: Revocación correcta
- **WHEN** se revoca un token vigente y API-Auth responde 200
- **THEN** la llamada termina sin error y la petición llevó el token como `Authorization: Bearer`

#### Scenario: Token ya revocado
- **WHEN** API-Auth responde 401 a la revocación
- **THEN** la llamada termina sin error

### Requirement: API-Auth no disponible
Si API-Auth no responde (error de conexión, timeout, 5xx) o su circuito está abierto, el canje y la revocación
SHALL lanzar `AuthServerUnavailable` y MUST usar el mismo circuito, timeouts y cabecera `traceparent` que las demás
llamadas a API-Auth. Con el circuito abierto no se envía ninguna petición.

#### Scenario: Circuito abierto
- **WHEN** el circuito hacia API-Auth está abierto y se canjea un código
- **THEN** se lanza `AuthServerUnavailable` sin enviar la petición

#### Scenario: Las fallas cuentan para el circuito
- **WHEN** el canje falla por error de conexión tantas veces seguidas como `UBO_AUTH_CIRCUIT_FAILURES`
- **THEN** el circuito se abre también para las validaciones y los tokens de servicio

#### Scenario: Se propaga la traza
- **WHEN** hay una traza activa y se canjea un código
- **THEN** la petición a API-Auth lleva la cabecera `traceparent`
