# Spec Delta

## MODIFIED Requirements

### Requirement: Errores al pedir el token
Si API-Auth no responde, MUST lanzarse `ServiceTokenException` indicando que no se pudo conectar. Si el
circuito hacia API-Auth está abierto, MUST lanzarse `ServiceTokenException` indicando que API-Auth no está
disponible, sin hacer la llamada. Si responde un error o una respuesta sin `access_token`, MUST lanzarse
`ServiceTokenException` con el status, el código `error` y una descripción armada con `error_description`
(o `message`) y `hint`. En los dos primeros casos, antes de lanzar la excepción MUST usarse el token de
reserva si existe (ver "Token de reserva si API-Auth no responde").

#### Scenario: Scope no permitido
- **WHEN** API-Auth responde 400 con `error=invalid_scope` y un `hint` que nombra el scope
- **THEN** la excepción trae `status=400`, `error=invalid_scope` y el `hint` en el mensaje

#### Scenario: API-Auth caído
- **WHEN** la petición de token falla por error de conexión y no hay un token vigente en caché
- **THEN** se lanza `ServiceTokenException` con el error de conexión como causa

#### Scenario: Circuito abierto sin token en caché
- **WHEN** el circuito hacia API-Auth está abierto y no hay un token vigente en caché
- **THEN** se lanza `ServiceTokenException` sin llamar a API-Auth

### Requirement: Caché por API y scopes
El token SHALL guardarse en caché, con una entrada distinta por client id, API y conjunto de scopes
(sin importar el orden ni los repetidos), junto con su expiración real (`expires_in`, 3600 si no viene).
MUST reutilizarse sin consultar a API-Auth hasta `service_token_margin` segundos (60) antes de expirar; a
partir de ahí se renueva. La entrada MUST conservarse en caché hasta la expiración real del token. El
token se guarda en claro (comportamiento conocido, hallazgo 8).

#### Scenario: Reutilización
- **WHEN** se pide dos veces un token para la misma API dentro de su vigencia
- **THEN** se hace una sola llamada a API-Auth

#### Scenario: Tokens de APIs distintas
- **WHEN** se pide un token para `api-maestros` y luego otro para `api-matricula`
- **THEN** se hacen dos llamadas y ninguno reemplaza al otro en caché

#### Scenario: Scopes en otro orden
- **WHEN** se pide `token(['b', 'a'])` y luego `token(['a', 'b', 'a'])`
- **THEN** la segunda llamada usa el token en caché de la primera

#### Scenario: Renovación anticipada
- **WHEN** se pide un token al que le quedan 30 segundos de vigencia y API-Auth responde
- **THEN** se pide un token nuevo y reemplaza al anterior en caché

## ADDED Requirements

### Requirement: Token de reserva si API-Auth no responde
Si al renovar un token API-Auth no responde (error de conexión, timeout, 5xx o circuito abierto) y el
token en caché todavía no expiró, SHALL devolverse ese token en lugar de lanzar `ServiceTokenException`.
Un rechazo de API-Auth (4xx) MUST lanzar la excepción aunque haya un token en caché. Un token expirado MUST
NOT devolverse nunca.

#### Scenario: API-Auth caído durante la renovación
- **WHEN** a un token en caché le quedan 30 segundos y la petición de renovación falla por timeout
- **THEN** se devuelve el token en caché

#### Scenario: Token ya expirado
- **WHEN** el token en caché expiró y API-Auth no responde
- **THEN** se lanza `ServiceTokenException`

#### Scenario: Credenciales revocadas
- **WHEN** a un token en caché le quedan 30 segundos y API-Auth responde 401 `invalid_client` a la renovación
- **THEN** se lanza `ServiceTokenException` con `status=401`
