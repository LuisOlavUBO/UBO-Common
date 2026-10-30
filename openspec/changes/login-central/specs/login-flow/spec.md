# Spec Delta

## Purpose

Permite que una aplicación Laravel autentique a sus usuarios en IAM (API-Auth) con Authorization Code y PKCE,
sin ver nunca su contraseña, y que cierre su sesión en IAM.

## ADDED Requirements

### Requirement: Inicio del login
`GET <prefijo>/login` SHALL redirigir al navegador al endpoint de autorización de IAM con `response_type=code`,
el client id del login, la `redirect_uri`, un `state` aleatorio y un `code_challenge` S256 calculado de un
verificador aleatorio, sin `scope`. El `state`, el verificador y el destino original MUST guardarse en la sesión,
con un vencimiento de 10 minutos.

#### Scenario: Redirección a IAM
- **WHEN** una persona sin sesión abre la ruta de login
- **THEN** es redirigida a `<URL pública de IAM>/api/auth/oauth/authorize` con `response_type=code`, `client_id`, `redirect_uri`, `state`, `code_challenge` y `code_challenge_method=S256`

#### Scenario: PKCE verificable
- **WHEN** se compara el `code_challenge` de la redirección con el verificador guardado en la sesión
- **THEN** el `code_challenge` es el SHA-256 del verificador en base64url sin relleno

#### Scenario: Cada inicio es distinto
- **WHEN** la misma persona abre dos veces la ruta de login
- **THEN** cada redirección lleva un `state` y un verificador nuevos, y solo la última queda en la sesión

### Requirement: URL pública y URL interna de IAM
El navegador SHALL ser enviado a la URL pública de IAM (`UBO_LOGIN_PUBLIC_URL`), y las llamadas de servidor a
servidor (canje, revocación) MUST usar la URL interna de API-Auth (`UBO_AUTH_URL`). Sin URL pública, se usa la
interna para ambas.

#### Scenario: Red interna distinta de la pública
- **WHEN** `UBO_LOGIN_PUBLIC_URL=https://iam.ubo.cl` y `UBO_AUTH_URL=http://api-auth`
- **THEN** la redirección al navegador va a `https://iam.ubo.cl/api/auth/oauth/authorize` y el canje del código se envía a `http://api-auth/api/auth/oauth/token`

#### Scenario: Sin URL pública
- **WHEN** no se define `UBO_LOGIN_PUBLIC_URL`
- **THEN** la redirección al navegador usa `UBO_AUTH_URL`

### Requirement: Redirección de retorno fija
La `redirect_uri` SHALL ser `UBO_LOGIN_REDIRECT_URI` o, si no se define, la ruta del callback bajo la URL de la
aplicación (`APP_URL`). MUST NOT calcularse a partir de la cabecera `Host` de la petición.

#### Scenario: Cabecera Host manipulada
- **WHEN** la petición de login llega con una cabecera `Host` distinta de la de `APP_URL`
- **THEN** la `redirect_uri` enviada a IAM sigue siendo la de `APP_URL`

### Requirement: Destino original
Tras el login, la persona SHALL volver a la página que intentaba abrir si es de la misma aplicación (ruta
relativa o mismo host que `APP_URL`); en otro caso, o sin destino, a `UBO_LOGIN_HOME` (`/` por defecto).

#### Scenario: Vuelve a la página pedida
- **WHEN** una persona sin sesión abre `/informes/3`, completa el login y vuelve a la aplicación
- **THEN** termina en `/informes/3`

#### Scenario: Destino de otro sitio
- **WHEN** la ruta de login recibe como destino la dirección de otro dominio
- **THEN** después del login la persona va a `UBO_LOGIN_HOME`

### Requirement: Volver a pedir las credenciales
Con `UBO_LOGIN_PROMPT=login`, toda redirección a IAM SHALL llevar `prompt=login`. La ruta de login con el
parámetro `reauth=1` MUST agregarlo solo en esa redirección.

#### Scenario: Siempre pedir la clave
- **WHEN** `UBO_LOGIN_PROMPT=login` y una persona abre la ruta de login
- **THEN** la redirección a IAM incluye `prompt=login`

#### Scenario: Para una acción sensible
- **WHEN** `UBO_LOGIN_PROMPT` no está definido y una persona abre la ruta de login con `reauth=1`
- **THEN** la redirección incluye `prompt=login`, y sin `reauth` no lo incluye

### Requirement: Validación del state en el callback
`GET <prefijo>/callback` SHALL aceptar el `code` solo si su `state` es igual al guardado en la sesión, no venció y
no se usó antes; el `state` guardado MUST descartarse al leerlo. En otro caso, SHALL lanzar `LoginFailed` (400) sin
llamar a IAM.

#### Scenario: State incorrecto
- **WHEN** el callback llega con un `state` distinto del de la sesión
- **THEN** se lanza `LoginFailed` con status 400 y no se hace ninguna llamada a IAM

#### Scenario: Callback sin inicio previo
- **WHEN** el callback llega a una sesión que no tiene `state` guardado
- **THEN** se lanza `LoginFailed` con status 400

#### Scenario: State vencido
- **WHEN** el callback llega más de 10 minutos después de iniciar el login
- **THEN** se lanza `LoginFailed` con status 400

#### Scenario: Callback repetido
- **WHEN** el mismo callback llega por segunda vez
- **THEN** se lanza `LoginFailed` con status 400

### Requirement: Error devuelto por IAM
Si el callback trae el parámetro `error`, SHALL lanzarse `LoginFailed` (403) con ese código de error, sin pedir
tokens, después de descartar el `state` guardado.

#### Scenario: Acceso denegado
- **WHEN** el callback llega con `error=access_denied` y un `state` válido
- **THEN** se lanza `LoginFailed` con status 403 y el código `access_denied`, y no se llama al endpoint de token

### Requirement: Canje del código
El módulo SHALL canjear el `code` en `POST /api/auth/oauth/token` de IAM con `grant_type=authorization_code`, el
`code`, la `redirect_uri`, el `code_verifier` guardado y las credenciales del cliente de login. Si IAM rechaza el
canje (por ejemplo `invalid_grant`), SHALL lanzarse `LoginFailed` (400) con el error de IAM; si IAM no responde o su
circuito está abierto, `AuthServerUnavailable` (503).

#### Scenario: Canje correcto
- **WHEN** el callback trae un `code` y un `state` válidos
- **THEN** se envía a IAM un canje con `grant_type=authorization_code`, ese `code`, la `redirect_uri` y el `code_verifier` de la sesión, con el client id y el secreto del login

#### Scenario: Código rechazado
- **WHEN** IAM responde 400 con `error=invalid_grant`
- **THEN** se lanza `LoginFailed` con status 400 y el código `invalid_grant`, y no se crea la sesión

#### Scenario: IAM caído
- **WHEN** el canje falla por error de conexión
- **THEN** se lanza `AuthServerUnavailable` y no se crea la sesión

### Requirement: Validación del token recibido
El token de acceso recibido SHALL validarse localmente (firma con la clave pública de IAM y vigencia) antes de
crear la sesión, y MUST haber sido emitido al cliente de login y a una persona (no a un cliente de sistema). Un
token inválido SHALL lanzar `LoginFailed` (400). La validación local no usa credenciales M2M ni consulta la
introspección.

#### Scenario: Token firmado con otra clave
- **WHEN** IAM responde un token firmado con una clave que no es la suya
- **THEN** se lanza `LoginFailed` y no se crea la sesión

#### Scenario: Token de otro cliente
- **WHEN** el token recibido tiene como primera audiencia un client id distinto del de login
- **THEN** se lanza `LoginFailed` y no se crea la sesión

#### Scenario: Sin credenciales M2M
- **WHEN** la aplicación no tiene `UBO_AUTH_CLIENT_ID` ni `UBO_AUTH_CLIENT_SECRET`
- **THEN** el login funciona igual, sin llamadas de introspección

### Requirement: Persona sin roles en la aplicación
Si el token recibido no trae ningún rol, SHALL lanzarse `NoAccess` (403) y MUST NOT crearse la sesión.

#### Scenario: Token sin roles
- **WHEN** IAM entrega un token cuya claim `roles` está vacía
- **THEN** se lanza `NoAccess` con status 403 y la persona no queda autenticada

### Requirement: Sesión nueva tras el login
Al crear la sesión autenticada, el módulo SHALL regenerar el identificador de sesión y el token CSRF, para que
un identificador anterior al login no sirva después.

#### Scenario: Fijación de sesión
- **WHEN** una persona completa el login con un identificador de sesión previo
- **THEN** la sesión autenticada tiene otro identificador y el anterior ya no lleva datos de la persona

### Requirement: Logout
`POST <prefijo>/logout` (protegida contra CSRF) SHALL revocar en IAM el token de la persona, cerrar la sesión
local (invalidarla y regenerar el CSRF) y redirigir a `<URL pública de IAM>/api/auth/logout` con `client_id` y
`return` (`UBO_LOGIN_LOGOUT_RETURN`, o `UBO_LOGIN_HOME`). IAM cierra entonces su sesión y revoca los tokens de la
persona en todos los sistemas. Si la revocación falla, el cierre local y la redirección MUST continuar.

#### Scenario: Cierre de sesión
- **WHEN** una persona autenticada envía el logout
- **THEN** se revoca su token en IAM, su sesión local queda vacía y es redirigida a `/api/auth/logout?client_id=<login>&return=<retorno>` de IAM

#### Scenario: IAM no responde al revocar
- **WHEN** la revocación del token falla por error de conexión
- **THEN** la sesión local se cierra igual, la persona es redirigida a IAM y el fallo queda en el log

#### Scenario: Sin sesión
- **WHEN** una persona sin sesión envía el logout
- **THEN** es redirigida a IAM como en el caso normal, sin intentar revocar

### Requirement: Logout solo local
El logout con `scope=local` SHALL revocar solo el token de esa sesión en IAM, cerrar la sesión local y redirigir a
`UBO_LOGIN_HOME`, sin pasar por el logout de IAM, de modo que las sesiones de la persona en otros sistemas siguen.

#### Scenario: Cierre local
- **WHEN** una persona envía el logout con `scope=local`
- **THEN** su sesión local se cierra, su token se revoca y es redirigida a `UBO_LOGIN_HOME` sin visitar IAM

### Requirement: Configuración incompleta
Si falta la URL de API-Auth, el client id o el secreto del login al usar las rutas, SHALL lanzarse un error de
configuración que nombre la variable faltante. La configuración MUST NOT validarse al registrar el módulo.

#### Scenario: Falta el secreto del cliente
- **WHEN** se abre la ruta de login sin `UBO_LOGIN_CLIENT_SECRET`
- **THEN** se lanza un error que nombra `UBO_LOGIN_CLIENT_SECRET`

#### Scenario: Build sin .env
- **WHEN** se ejecuta `composer install` en un build sin variables de login
- **THEN** el descubrimiento de paquetes termina sin errores
