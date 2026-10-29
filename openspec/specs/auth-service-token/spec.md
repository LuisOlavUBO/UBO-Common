# auth-service-token Specification

## Purpose

Permite que la propia aplicación obtenga de API-Auth tokens de acceso (client credentials) para
llamar a la introspección o a otras APIs de la UBO, reutilizándolos mientras sean vigentes.

## Requirements

### Requirement: Obtención de un token propio
`ServiceTokenProvider::token($scopes, $api)` SHALL pedir un token a API-Auth (`POST /api/auth/oauth/token`,
formulario con `grant_type=client_credentials`, `client_id`, `client_secret` y, si vienen, `scope`
separado por espacios y `api`). Sin `UBO_AUTH_URL`, `UBO_AUTH_CLIENT_ID` o `UBO_AUTH_CLIENT_SECRET` MUST
lanzar `ServiceTokenException` sin hacer la llamada.

#### Scenario: Token para una API
- **WHEN** se llama a `token(api: 'api-maestros')` con las credenciales configuradas
- **THEN** se envía `api=api-maestros` sin `scope` y se devuelve el `access_token` de la respuesta

#### Scenario: Credenciales sin configurar
- **WHEN** falta `UBO_AUTH_CLIENT_SECRET` y se pide un token
- **THEN** se lanza `ServiceTokenException` indicando las variables requeridas, sin llamar a API-Auth

### Requirement: Errores al pedir el token
Si API-Auth no responde, MUST lanzarse `ServiceTokenException` indicando que no se pudo conectar. Si el
circuito hacia API-Auth está abierto, MUST lanzarse `ServiceTokenException` indicando que API-Auth no está
disponible, sin hacer la llamada. Si responde un error o una respuesta sin `access_token`, MUST lanzarse
`ServiceTokenException` con el status, el código `error` y una descripción armada con `error_description`
(o `message`) y `hint`. Los dos primeros casos aplican solo sin token de reserva.

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

### Requirement: Una sola petición concurrente por token
Cuando el almacén de caché admite candados, la obtención de un mismo token SHALL protegerse con un
candado, de modo que los procesos que esperan reutilicen el token que obtuvo el primero. Si el candado no
se obtiene en 5 segundos o el almacén no admite candados, MUST pedirse el token igualmente.

#### Scenario: Varios workers sin token en caché
- **WHEN** varios procesos piden a la vez el mismo token que no está en caché
- **THEN** solo uno llama a API-Auth y los demás reciben el token que quedó en caché

### Requirement: Descartar un token en caché
`ServiceTokenProvider::forget($scopes, $api)` SHALL borrar de la caché el token de esa API y scopes, para
que la siguiente petición obtenga uno nuevo.

#### Scenario: Token rechazado por la API destino
- **WHEN** se llama a `forget(api: 'api-maestros')` y luego a `token(api: 'api-maestros')`
- **THEN** se pide un token nuevo a API-Auth

### Requirement: Almacén y prefijo de caché configurables
Las entradas de caché del módulo SHALL usar el almacén `UBO_AUTH_CACHE_STORE` (el por defecto de la
aplicación si está vacío) y el prefijo `ubo-auth:`.

#### Scenario: Almacén dedicado
- **WHEN** `UBO_AUTH_CACHE_STORE=redis`
- **THEN** los tokens propios, la clave pública y la introspección se guardan en ese almacén

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
