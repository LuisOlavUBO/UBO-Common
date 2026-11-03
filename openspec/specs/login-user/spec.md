# login-user Specification

## Purpose
Define la sesión del monolito tras el login y el usuario autenticado que la aplicación obtiene de ella: quién
es, qué roles y scopes tiene, y hasta cuándo, más la ayuda para probar rutas sin IAM.

## Requirements

### Requirement: Guard del usuario de IAM
El módulo SHALL ofrecer el guard `ubo` (`Auth::guard('ubo')`, `auth:ubo`), disponible sin configurarlo en
`config/auth.php` y sin cambiar el guard por defecto de la aplicación. Una aplicación MAY declarar un guard propio
con `driver => 'ubo'`. El guard no necesita un proveedor de usuarios.

#### Scenario: Guard disponible sin configuración
- **WHEN** una aplicación activa el módulo `login` sin tocar `config/auth.php`
- **THEN** `Auth::guard('ubo')` existe y `config('auth.defaults.guard')` no cambia

#### Scenario: Guard propio
- **WHEN** la aplicación declara `'web' => ['driver' => 'ubo']` en `config/auth.php`
- **THEN** `Auth::guard('web')` usa la sesión de IAM

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

### Requirement: Token de acceso solo en el servidor
El token de acceso SHALL guardarse en la sesión del servidor y MUST NOT enviarse al navegador. El módulo MUST
negarse a crear la sesión si el controlador de sesión es `cookie`, que guarda los datos en el navegador.

#### Scenario: Controlador de sesión cookie
- **WHEN** `SESSION_DRIVER=cookie` y una persona completa el callback
- **THEN** se lanza un error que explica que los tokens no pueden guardarse en una cookie, y no se crea la sesión

#### Scenario: Respuesta sin tokens
- **WHEN** el callback redirige a la aplicación tras un login correcto
- **THEN** ninguna cabecera ni cookie de la respuesta contiene el token de acceso

### Requirement: Fin de la sesión al vencer el token
La sesión del login SHALL terminar cuando vence el token de acceso (descontando `UBO_AUTH_LEEWAY`): el guard
MUST devolver `null` y descartar sus datos. Esta parte no renueva el token: la persona vuelve a pasar por el
login, que es transparente si su sesión de IAM sigue abierta.

#### Scenario: Token vencido
- **WHEN** pasa el tiempo de vida del token de acceso desde el login
- **THEN** `check()` es falso y la sesión del login queda vacía

#### Scenario: Token vigente
- **WHEN** la petición llega antes de que venza el token
- **THEN** el usuario sigue autenticado

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
