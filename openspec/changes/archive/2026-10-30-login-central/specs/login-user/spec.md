# Spec Delta

## Purpose

Define la sesión del monolito tras el login y el usuario autenticado que la aplicación obtiene de ella: quién
es, qué roles y scopes tiene, y hasta cuándo, más la ayuda para probar rutas sin IAM.

## ADDED Requirements

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
(`getAuthIdentifier()`) es el `sub` del token, con `roles`, `scopes`, `audiences` y `clientId` del token. `hasRole($name)`
SHALL ser verdadero solo con el nombre exacto, y `hasScope($scope)` con un scope que el token tiene. `id()` y
`check()` MUST reflejarlo.

#### Scenario: Datos del token
- **WHEN** una persona inició sesión con un token de `sub=42`, roles `["coordinador"]` y scope `geografia:leer`
- **THEN** el guard devuelve un usuario con id `42`, `hasRole('coordinador')` verdadero y `hasScope('geografia:leer')` verdadero

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
`UboLogin::actingAs()` SHALL dejar autenticada en la prueba a una persona con el id, roles, scopes y audiencias
indicados, sin llamar a IAM ni pasar por el login, de modo que las rutas protegidas por el módulo la vean como
autenticada. En producción MUST fallar con `LogicException`.

#### Scenario: Ruta protegida en una prueba
- **WHEN** una prueba llama a `UboLogin::actingAs(id: '42', roles: ['coordinador'], scopes: ['geografia:leer'])` y pide una ruta con `ubo.login`
- **THEN** la ruta responde como a una persona autenticada con ese rol y ese scope, sin ninguna llamada de red

#### Scenario: En producción
- **WHEN** se llama a `UboLogin::actingAs()` con `APP_ENV=production`
- **THEN** se lanza `LogicException`
