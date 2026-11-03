# Spec Delta

## MODIFIED Requirements

### Requirement: Token por API obtenido automáticamente
Sin `->asUser()`, cada llamada SHALL llevar `Authorization: Bearer` con un token propio emitido para esa API,
sin scopes explícitos, de modo que trae todos los scopes que los roles del sistema tienen ahí (comportamiento
conocido, hallazgo 5). Si no se obtiene el token, MUST lanzarse `UboApiException` sin llamar a la API.

#### Scenario: Sistema sin permisos en la API
- **WHEN** API-Auth rechaza el token para `api-maestros`
- **THEN** se lanza `UboApiException` que indica que no se pudo obtener el token, sin llamar a la API

## ADDED Requirements

### Requirement: Llamada en nombre de la persona con sesión
`->asUser()` antes del verbo SHALL hacer que la llamada lleve `Authorization: Bearer` con el token vigente de la
persona que tiene sesión en la aplicación (módulo Login), en vez del token propio, en `call()` y en
`callAsync()`. No MUST pedirse ningún token propio a API-Auth. El circuito por API, el tiempo total, los
reintentos y la traza MUST aplicar igual que sin `->asUser()`.

#### Scenario: Llamada como la persona
- **WHEN** una persona tiene sesión y se llama a `UboApi::call('api-maestros', $url)->asUser()->get('/api/maestros/v1/geografia/paises')`
- **THEN** la petición lleva el token de acceso de esa persona y no se pide un token a API-Auth

#### Scenario: Llamada asíncrona como la persona
- **WHEN** una persona tiene sesión y se crea una promesa con `callAsync(...)->asUser()->get(...)`
- **THEN** la petición lleva el token de la persona vigente al crear la promesa

#### Scenario: Sin asUser
- **WHEN** una persona tiene sesión y se llama sin `->asUser()`
- **THEN** la petición lleva el token propio de la aplicación, como siempre

### Requirement: Renovación del token de la persona ante 401
Con `->asUser()`, si la API responde 401, la llamada SHALL pedir de nuevo el token de la persona indicando el
token rechazado y reintentar una vez de inmediato con el que reciba, además de los reintentos configurados. Si
no hay un token distinto, o un segundo intento responde 401, MUST lanzarse `UboApiException`.

#### Scenario: Otra petición renovó el token
- **WHEN** la API responde 401 a un token que otra petición acaba de renovar, y luego 200 con el token nuevo
- **THEN** la llamada devuelve la respuesta 200

#### Scenario: Token que no se puede renovar
- **WHEN** la API responde 401 y la persona no tiene un token distinto
- **THEN** se lanza `UboApiException` con `status=401` sin un segundo envío

### Requirement: Llamada como persona sin sesión
Si no hay una persona con sesión (petición sin sesión, sesión terminada o vencida sin poder renovarse, cola o
comando), `->asUser()` SHALL lanzar `Illuminate\Auth\AuthenticationException` sin enviar la petición.

#### Scenario: Ruta sin login
- **WHEN** se llama con `->asUser()` en una petición sin sesión del login
- **THEN** se lanza `AuthenticationException` y no se envía nada a la API

#### Scenario: Desde un job
- **WHEN** un job de una cola llama con `->asUser()`
- **THEN** se lanza `AuthenticationException` y no se envía nada a la API

### Requirement: asUser requiere el módulo login
Con el módulo `login` apagado, `->asUser()` SHALL lanzar `LogicException` que indica activar
`ubo-common.modules.login`.

#### Scenario: Módulo login apagado
- **WHEN** `UBO_COMMON_LOGIN=false` y se llama con `->asUser()`
- **THEN** se lanza `LogicException` que nombra `ubo-common.modules.login`

### Requirement: Pruebas de llamadas como persona
`UboLogin::actingAs()` y `UboApi::fake()` SHALL poder combinarse: tras `actingAs()`, una llamada con
`->asUser()` MUST ir a la API simulada con el token de la persona simulada.

#### Scenario: Persona simulada y API simulada
- **WHEN** un test llama a `UboLogin::actingAs(id: '42')`, `UboApi::fake(['api-maestros/*' => ['data' => []]])` y luego a `call('api-maestros', null)->asUser()->get(...)`
- **THEN** la llamada devuelve 200 y `Http::assertSent()` ve el token de la persona simulada
