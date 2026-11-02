# Spec Delta

## MODIFIED Requirements

### Requirement: Token de acceso solo en el servidor
Los tokens (acceso y refresh) SHALL guardarse en una bóveda del servidor (el almacén `UBO_LOGIN_STORE`), cifrados,
bajo un identificador aleatorio que es lo único que guarda la sesión, y MUST NOT enviarse al navegador. El identificador
MUST cambiar en cada login y la bóveda borrarse al cerrar la sesión. El módulo MUST negarse a crear la sesión si el
controlador de sesión es `cookie`, que guarda los datos en el navegador.

#### Scenario: Controlador de sesión cookie
- **WHEN** `SESSION_DRIVER=cookie` y una persona completa el callback
- **THEN** se lanza un error que explica que los tokens no pueden guardarse en una cookie, y no se crea la sesión

#### Scenario: Respuesta sin tokens
- **WHEN** el callback redirige a la aplicación tras un login correcto
- **THEN** ninguna cabecera ni cookie de la respuesta contiene el token de acceso ni el refresh token

#### Scenario: La sesión no guarda tokens
- **WHEN** una persona completa el login
- **THEN** los datos de la sesión del servidor no contienen el token de acceso ni el refresh token, solo el identificador de la bóveda

#### Scenario: Cifrado en el almacén
- **WHEN** una persona completa el login
- **THEN** el valor guardado en el almacén no contiene el token de acceso ni el refresh token en claro

#### Scenario: Identificador nuevo en cada login
- **WHEN** la misma persona completa dos logins seguidos
- **THEN** cada login usa un identificador distinto y el del primero ya no da acceso

#### Scenario: Cierre de sesión
- **WHEN** la persona cierra la sesión
- **THEN** la entrada de la bóveda se borra

## REMOVED Requirements

### Requirement: Fin de la sesión al vencer el token
**Reason**: La parte 1 del login no renovaba el token y la sesión terminaba al vencer; ahora se renueva con el refresh
token y la sesión termina por los motivos de "Fin de la sesión del login".
**Migration**: Sin cambios para las aplicaciones: el guard sigue devolviendo `null` cuando la sesión termina.

## ADDED Requirements

### Requirement: Renovación del token de acceso
Cuando al token de acceso le quedan `UBO_LOGIN_REFRESH_MARGIN` segundos (60) o menos, el guard SHALL renovarlo con el
refresh token, guardar los tokens nuevos y actualizar los roles, scopes y audiencias del usuario con los del token
nuevo, que se valida como en el login y debe ser de la misma persona. Con varias peticiones a la vez MUST renovarse
una sola vez, y las demás usar el resultado.

#### Scenario: Renovación antes de vencer
- **WHEN** a la persona le quedan menos de 60 segundos de token y hace una petición
- **THEN** se renueva con el refresh token, el usuario sigue autenticado y la bóveda guarda el token y el refresh token nuevos

#### Scenario: Sin renovar antes del margen
- **WHEN** a la persona le quedan 10 minutos de token
- **THEN** la petición no llama a IAM

#### Scenario: Roles actualizados
- **WHEN** IAM renueva el token con otros roles que los del login
- **THEN** `roles` y `scopes` del usuario pasan a ser los del token nuevo

#### Scenario: Otra petición ya renovó
- **WHEN** al llegar a renovar, la bóveda ya tiene un token renovado por otra petición
- **THEN** se usa ese token y no se llama a IAM

#### Scenario: Refresh token rotado por otra petición
- **WHEN** IAM rechaza el refresh token con `invalid_grant` y la bóveda ya guarda otro refresh token
- **THEN** la sesión no termina y se usa el token de la bóveda

#### Scenario: Token renovado de otra persona
- **WHEN** el token que IAM devuelve al renovar es de otra persona o de otro cliente
- **THEN** la sesión termina

### Requirement: Fin de la sesión del login
La sesión del login SHALL terminar (el guard devuelve `null` y la bóveda se borra) cuando IAM rechaza el refresh token
con `invalid_grant` sin que otra petición lo haya renovado, cuando el token renovado no trae ningún rol, o cuando el
token de acceso venció y no hay refresh token. La siguiente petición pasa de nuevo por el login de IAM, que muestra su
página de "sin acceso" a quien perdió todos sus roles.

#### Scenario: Refresh token rechazado
- **WHEN** IAM responde `invalid_grant` al renovar y la bóveda sigue guardando ese mismo refresh token
- **THEN** `check()` es falso y la entrada de la bóveda se borra

#### Scenario: La persona perdió todos sus roles
- **WHEN** IAM renueva el token con `roles` vacío
- **THEN** `check()` es falso, la bóveda se borra y la persona no vuelve a entrar con ese token

#### Scenario: Sin refresh token
- **WHEN** IAM no entregó refresh token en el login y el token de acceso vence
- **THEN** `check()` es falso y la bóveda se borra

### Requirement: IAM no responde al renovar
Si IAM no responde al renovar (error de conexión, circuito abierto, 5xx) o rechaza la renovación por otro motivo que
`invalid_grant` (por ejemplo 429), el guard SHALL seguir con el token vigente mientras no venza, y cuando ya venció
MUST devolver `null` sin borrar la bóveda, para reintentar en la siguiente petición.

#### Scenario: IAM caído con token vigente
- **WHEN** a la persona le quedan 30 segundos de token e IAM no responde a la renovación
- **THEN** la persona sigue autenticada con el token actual

#### Scenario: IAM caído con token vencido
- **WHEN** el token venció e IAM no responde a la renovación
- **THEN** `check()` es falso pero la bóveda conserva sus tokens, y una petición posterior con IAM disponible renueva

#### Scenario: Límite de solicitudes
- **WHEN** IAM responde 429 a la renovación con el token todavía vigente
- **THEN** la persona sigue autenticada y no se borra nada

#### Scenario: Una sola pregunta por petición
- **WHEN** IAM no responde o responde 429 a la renovación y la misma petición consulta el guard varias veces
- **THEN** IAM recibe una sola llamada en esa petición, y la siguiente petición vuelve a intentar

### Requirement: Revalidación periódica
Cada `UBO_LOGIN_REVALIDATE` segundos (300; 0 la apaga) el guard SHALL comprobar con IAM, por la estrategia de
validación configurada, que el token de acceso sigue activo, para enterarse de un logout hecho en otro sistema, y
terminar la sesión si ya no lo está. Solo se hace con las estrategias `introspection` e `hybrid` y con credenciales
M2M configuradas; si IAM no responde, la sesión sigue y se reintenta en la siguiente petición.

#### Scenario: Token revocado en otro sistema
- **WHEN** pasan 5 minutos desde la última comprobación y IAM informa que el token está inactivo
- **THEN** `check()` es falso y la bóveda se borra

#### Scenario: Sin comprobar antes de tiempo
- **WHEN** la última comprobación fue hace un minuto
- **THEN** la petición no llama a IAM

#### Scenario: Estrategia jwt o sin credenciales M2M
- **WHEN** `UBO_AUTH_STRATEGY=jwt`, o no hay credenciales M2M, y pasan 5 minutos
- **THEN** no se hace ninguna comprobación y la sesión sigue

#### Scenario: IAM caído en la comprobación
- **WHEN** la comprobación falla porque IAM no responde
- **THEN** la persona sigue autenticada, la comprobación no se repite dentro de la misma petición y se reintenta en la siguiente

### Requirement: Tope de duración de la sesión
La sesión del login SHALL terminar a los `UBO_LOGIN_MAX_AGE` segundos (43200, 12 horas; 0 sin tope) desde el login,
aunque se haya renovado el token sin interrupción, y la persona vuelve a pasar por IAM.

#### Scenario: Tope alcanzado
- **WHEN** pasan 12 horas desde el login con renovaciones correctas
- **THEN** `check()` es falso y la bóveda se borra

#### Scenario: Sin tope
- **WHEN** `UBO_LOGIN_MAX_AGE=0` y pasan 24 horas con renovaciones correctas
- **THEN** la persona sigue autenticada
