# Spec Delta

## Purpose

Permite que una API de la UBO confíe en los tokens que emite API-Auth sin tener su propio servidor
OAuth: valida firma, vigencia, tipo, audiencia, revocación y scopes, y falla cerrado cuando no puede
verificar.

## ADDED Requirements

### Requirement: Middleware de autenticación
El módulo SHALL registrar el alias de middleware `ubo.auth`. Una ruta protegida MUST rechazar con 401
(`InvalidToken`, motivo `missing_token`) toda petición sin `Authorization: Bearer <token>` o con un token
vacío.

#### Scenario: Petición sin token
- **WHEN** una petición llega a una ruta con `ubo.auth` sin header `Authorization`
- **THEN** se lanza `InvalidToken` con motivo `missing_token` y la respuesta es 401

#### Scenario: Token válido
- **WHEN** una petición trae un token M2M válido, emitido para esta API y con los scopes de la ruta
- **THEN** la petición llega al controlador

### Requirement: Scopes requeridos por la ruta
El middleware SHALL aceptar scopes como parámetros (`ubo.auth:a,b`) y MUST exigir que el token tenga
todos ellos. El scope `*` en el token satisface cualquier scope. Si falta alguno, MUST lanzar
`InsufficientScope` (403), cuyo método `scopes()` devuelve los scopes faltantes.

#### Scenario: Falta un scope
- **WHEN** la ruta exige `geografia:leer,sistemas:leer` y el token solo tiene `geografia:leer`
- **THEN** se lanza `InsufficientScope` con `scopes() = ['sistemas:leer']` y la respuesta es 403

#### Scenario: Token con scope comodín
- **WHEN** la ruta exige `geografia:leer` y el token tiene el scope `*`
- **THEN** la petición se acepta

#### Scenario: Ruta sin scopes
- **WHEN** la ruta usa `ubo.auth` sin parámetros
- **THEN** se acepta cualquier token válido, sin revisar scopes

### Requirement: Token verificado disponible en la petición
Tras validar, el middleware SHALL dejar el token en la petición, accesible con
`VerifiedToken::from($request)`, que expone `id`, `clientId`, `userId`, `scopes`, `expiresAt`, `audiences`,
`hasScope()` e `isMachineToken()`. Sin token validado, `from()` MUST devolver `null`.

#### Scenario: Leer el token en el controlador
- **WHEN** un controlador detrás de `ubo.auth` llama a `VerifiedToken::from($request)`
- **THEN** obtiene el `clientId` y los `scopes` del token que llamó

#### Scenario: Ruta sin el middleware
- **WHEN** se llama a `VerifiedToken::from($request)` en una ruta sin `ubo.auth`
- **THEN** devuelve `null`

### Requirement: Selección de la estrategia de validación
La estrategia SHALL elegirse con `UBO_AUTH_STRATEGY` entre `jwt`, `introspection` y `hybrid`, con
`hybrid` por defecto. Un valor distinto MUST fallar con `InvalidArgumentException` al resolver el
validador. La configuración se lee en cada uso, de modo que un cambio en tiempo de ejecución se aplica
de inmediato.

#### Scenario: Estrategia desconocida
- **WHEN** `UBO_AUTH_STRATEGY=oauth` y llega una petición a una ruta protegida
- **THEN** falla con `InvalidArgumentException` indicando los valores permitidos

### Requirement: Validación local de firma
Con las estrategias `jwt` e `hybrid`, el token SHALL validarse localmente con la clave pública de
API-Auth y MUST aceptarse solo si está firmado con RS256 por esa clave. Un token con otro algoritmo, mal
firmado o que no es un JWT válido MUST rechazarse con 401 (`invalid_signature` o `malformed_token`).

#### Scenario: Token falsificado
- **WHEN** llega un token firmado con una clave que no es la de API-Auth
- **THEN** se rechaza con 401 y motivo `invalid_signature`

#### Scenario: Algoritmo distinto de RS256
- **WHEN** llega un token con `alg` `HS256` o `none`
- **THEN** se rechaza con 401

#### Scenario: Texto que no es un JWT
- **WHEN** el bearer es una cadena que no se puede parsear como JWT
- **THEN** se rechaza con 401 y motivo `malformed_token`

### Requirement: Claims obligatorios del token
Un token bien firmado MUST tener `jti` como texto, un primer `aud` no vacío (el client id) y `scopes`
como arreglo; si no, MUST rechazarse con 401 `malformed_token`. El usuario se toma de `sub`, salvo que
`sub` esté vacío o sea igual al client id sin distinguir mayúsculas, en cuyo caso es un token M2M.

#### Scenario: Token sin jti
- **WHEN** llega un token bien firmado sin claim `jti`
- **THEN** se rechaza con 401 y motivo `malformed_token`

#### Scenario: sub igual al client id con otra capitalización
- **WHEN** el `sub` es `ABC-123` y el client id es `abc-123`
- **THEN** el token se trata como M2M (`userId` es `null`)

### Requirement: Vigencia del token
El token MUST rechazarse con 401 (`expired_or_not_yet_valid`) si está vencido (`exp`), aún no es válido
(`nbf`) o fue emitido en el futuro (`iat`), con una tolerancia de `UBO_AUTH_LEEWAY` segundos (10 por
defecto).

#### Scenario: Token vencido
- **WHEN** llega un token cuyo `exp` pasó hace más de 10 segundos
- **THEN** se rechaza con 401 y motivo `expired_or_not_yet_valid`

#### Scenario: Diferencia de reloj dentro de la tolerancia
- **WHEN** llega un token cuyo `exp` pasó hace 5 segundos con `UBO_AUTH_LEEWAY=10`
- **THEN** el token se acepta

### Requirement: Origen de la clave pública
Si `UBO_AUTH_PUBLIC_KEY` está definida (texto PEM, con `\n` literales permitidos, o ruta a un archivo),
la clave SHALL tomarse de ahí sin llamadas de red. Si no, MUST descargarse de API-Auth
(`GET <UBO_AUTH_URL>/api/auth/v1/public-key`) y guardarse en caché `UBO_AUTH_PUBLIC_KEY_TTL` segundos (86400).

#### Scenario: Clave fija en configuración
- **WHEN** `UBO_AUTH_PUBLIC_KEY` contiene una clave PEM y la estrategia es `jwt`
- **THEN** la API valida tokens sin hacer ninguna llamada a API-Auth

#### Scenario: Ruta de clave ilegible
- **WHEN** `UBO_AUTH_PUBLIC_KEY` no es PEM ni un archivo legible
- **THEN** la validación falla con un `RuntimeException` que lo indica

#### Scenario: Respuesta que no es una clave
- **WHEN** el endpoint de clave pública responde un error o un cuerpo sin `BEGIN PUBLIC KEY`
- **THEN** la validación falla con `AuthServerUnavailable` (503)

### Requirement: Rotación de la clave pública
Si la firma no valida con la clave en caché, la clave descargada SHALL volver a descargarse y el token
reintentarse una vez. Las descargas forzadas MUST limitarse a una cada 60 segundos, y si la descarga
falla MUST conservarse la clave en caché y el token tratarse como mal firmado (401).

#### Scenario: API-Auth rotó su clave
- **WHEN** llega un token firmado con la nueva clave de API-Auth y la caché tiene la anterior
- **THEN** se descarga la clave nueva, se reemplaza en caché y el token se acepta

#### Scenario: Ráfaga de tokens falsificados
- **WHEN** llegan muchos tokens mal firmados en menos de 60 segundos
- **THEN** se hace como máximo una descarga forzada de la clave en ese intervalo

#### Scenario: API-Auth caído durante la rotación
- **WHEN** la firma no valida y la descarga forzada de la clave falla
- **THEN** la clave en caché se mantiene y el token se rechaza con 401 `invalid_signature`

### Requirement: Solo tokens M2M por defecto
El middleware MUST rechazar con 401 (`user_token_not_allowed`) los tokens emitidos a un usuario, salvo
que `UBO_AUTH_ALLOW_USER_TOKENS=true`.

#### Scenario: Token de usuario con la opción por defecto
- **WHEN** llega un token válido con un `sub` de usuario y `UBO_AUTH_ALLOW_USER_TOKENS` no está definido
- **THEN** se rechaza con 401 y motivo `user_token_not_allowed`

#### Scenario: Tokens de usuario permitidos
- **WHEN** `UBO_AUTH_ALLOW_USER_TOKENS=true` y llega un token de usuario válido con los scopes de la ruta
- **THEN** la petición se acepta

### Requirement: Audiencia de los tokens M2M
Si `UBO_AUTH_API` está definida, un token M2M MUST incluir ese nombre en su `aud`, o se rechaza con 401
(`wrong_audience`) aunque tenga los scopes de la ruta. Sin `UBO_AUTH_API` no se revisa la audiencia. Los
tokens de usuario no se revisan contra la audiencia (comportamiento conocido, hallazgo 7).

#### Scenario: Token emitido para otra API
- **WHEN** `UBO_AUTH_API=api-maestros` y llega un token M2M con `aud = ["SIS", "api-matricula"]`
- **THEN** se rechaza con 401 y motivo `wrong_audience`

#### Scenario: API sin nombre configurado
- **WHEN** `UBO_AUTH_API` no está definida y llega un token M2M válido emitido para otra API
- **THEN** el token se acepta

#### Scenario: Token de usuario de otra API
- **WHEN** `UBO_AUTH_API=api-maestros`, se permiten tokens de usuario y llega uno sin `api-maestros` en `aud`
- **THEN** el token se acepta

### Requirement: Validación por introspección
Con la estrategia `introspection`, cada token SHALL consultarse a API-Auth (`POST /api/auth/v1/introspect`)
autenticándose con un token propio de scope `introspect`. Una respuesta sin `active: true`, `client_id`
o `exp` numérico MUST tratarse como token inactivo (401 `inactive_token`). Los scopes salen del claim `scope`.

#### Scenario: Token revocado
- **WHEN** API-Auth responde `{"active": false}` para el token
- **THEN** se rechaza con 401 y motivo `inactive_token`

#### Scenario: Cualquier texto llega a API-Auth
- **WHEN** con la estrategia `introspection` llega un bearer aleatorio que no está en caché
- **THEN** se hace una llamada de introspección a API-Auth (comportamiento conocido, hallazgo 9)

### Requirement: Renovación del token propio ante 401 de introspección
Si API-Auth responde 401 a la introspección, el token propio de scope `introspect` SHALL descartarse y
la consulta MUST reintentarse una vez con un token nuevo.

#### Scenario: Token propio revocado
- **WHEN** la introspección responde 401 y la segunda, con un token propio nuevo, responde 200 activo
- **THEN** el token del cliente se acepta

### Requirement: Caché de resultados de introspección
Los resultados de introspección SHALL guardarse en caché con una clave derivada del hash SHA-256 del
token, nunca del token en claro. Un resultado activo MUST guardarse por el menor entre
`UBO_AUTH_INTROSPECTION_TTL` (30 s) y lo que le queda al token; uno inactivo, por `UBO_AUTH_INTROSPECTION_TTL`.

#### Scenario: Segunda petición con el mismo token
- **WHEN** el mismo token llega dos veces dentro de 30 segundos
- **THEN** se hace una sola llamada de introspección

#### Scenario: Revocación detectada con retraso acotado
- **WHEN** un token se revoca en API-Auth después de haberse validado
- **THEN** se rechaza como máximo `UBO_AUTH_INTROSPECTION_TTL` segundos después

### Requirement: Estrategia híbrida
Con la estrategia `hybrid`, el token SHALL validarse primero localmente y solo si pasa MUST consultarse
por introspección. Los datos del token aceptado salen de la validación local.

#### Scenario: Token falsificado con hybrid
- **WHEN** con `hybrid` llega un token mal firmado
- **THEN** se rechaza con 401 sin llamar a la introspección de API-Auth

#### Scenario: Token bien firmado pero revocado con hybrid
- **WHEN** con `hybrid` llega un token bien firmado que API-Auth reporta inactivo
- **THEN** se rechaza con 401 y motivo `inactive_token`

### Requirement: Fallar cerrado si API-Auth no responde
Cuando la validación necesita a API-Auth y no puede obtener una respuesta válida (sin `UBO_AUTH_URL`,
error de conexión, respuesta no exitosa o sin poder obtener el token propio), MUST lanzar
`AuthServerUnavailable` (503) y nunca dejar pasar la petición.

#### Scenario: Introspección caída
- **WHEN** con `hybrid` llega un token bien firmado y API-Auth no responde
- **THEN** la respuesta es 503 y la petición no llega al controlador

#### Scenario: Introspección responde 500
- **WHEN** la introspección responde 500
- **THEN** se lanza `AuthServerUnavailable`

### Requirement: Tiempo de espera de las llamadas a API-Auth
Cada llamada a API-Auth (clave pública, introspección, token propio) SHALL usar el timeout de
`UBO_AUTH_TIMEOUT` segundos (5 por defecto) y propagar el header `traceparent` cuando el módulo `tracing`
está activo. No hay un timeout de conexión separado (comportamiento conocido, hallazgo 2).

#### Scenario: API-Auth lento
- **WHEN** API-Auth tarda más de `UBO_AUTH_TIMEOUT` segundos en responder la introspección
- **THEN** la validación falla con `AuthServerUnavailable` (503)

### Requirement: Excepciones compatibles con Laravel
`InvalidToken` SHALL heredar de `AuthenticationException` e `InsufficientScope` de
`AuthorizationException`, para que cada aplicación las presente con su formato. `InvalidToken::$reason`
MUST indicar el motivo para diagnóstico y no exponerse al cliente; hoy no se registra en logs
(comportamiento conocido, hallazgo 10).

#### Scenario: Aplicación sin el módulo Problems
- **WHEN** una API sin `ProblemRenderer` recibe una petición sin token
- **THEN** Laravel presenta el `AuthenticationException` con su formato por defecto

### Requirement: Comando de diagnóstico
El comando `php artisan ubo:auth:check` SHALL mostrar la estrategia, la URL de API-Auth y el nombre de la
API, verificar que la clave pública se obtiene y es un PEM válido, y pedir un token propio de scope
`introspect` cuando hay credenciales. MUST terminar con código de error si alguna revisión falla o si la
estrategia no es `jwt` y faltan credenciales.

#### Scenario: Todo configurado
- **WHEN** se ejecuta el comando con URL, credenciales válidas y API-Auth disponible
- **THEN** cada revisión muestra OK y el comando termina con éxito

#### Scenario: Estrategia hybrid sin credenciales
- **WHEN** la estrategia es `hybrid` y no hay `UBO_AUTH_CLIENT_ID` o `UBO_AUTH_CLIENT_SECRET`
- **THEN** el comando informa que la estrategia necesita credenciales y termina con error

#### Scenario: Estrategia jwt sin credenciales
- **WHEN** la estrategia es `jwt`, la clave pública es válida y no hay credenciales
- **THEN** el comando informa que las credenciales no son necesarias y termina con éxito
