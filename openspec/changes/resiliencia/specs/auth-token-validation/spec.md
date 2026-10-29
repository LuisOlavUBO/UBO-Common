# Spec Delta

## MODIFIED Requirements

### Requirement: Tiempo de espera de las llamadas a API-Auth
Cada llamada a API-Auth (clave pública, introspección, token propio) SHALL usar un timeout de conexión
de `UBO_AUTH_CONNECT_TIMEOUT` segundos (2 por defecto) y un timeout total de `UBO_AUTH_TIMEOUT` segundos
(5 por defecto), y propagar el header `traceparent` cuando el módulo `tracing` está activo. Si el timeout
de conexión es mayor que el total, MUST usarse el total.

#### Scenario: API-Auth lento
- **WHEN** API-Auth tarda más de `UBO_AUTH_TIMEOUT` segundos en responder la introspección
- **THEN** la validación falla con `AuthServerUnavailable` (503)

#### Scenario: Host de API-Auth que no acepta conexiones
- **WHEN** la conexión TCP con API-Auth no se establece
- **THEN** la llamada falla a los `UBO_AUTH_CONNECT_TIMEOUT` segundos, sin esperar `UBO_AUTH_TIMEOUT`

### Requirement: Fallar cerrado si API-Auth no responde
Cuando la validación necesita a API-Auth y no puede obtener una respuesta válida (sin `UBO_AUTH_URL`,
error de conexión, respuesta no exitosa, circuito abierto o sin poder obtener el token propio), MUST lanzar
`AuthServerUnavailable` (503) y nunca dejar pasar la petición, salvo con el modo degradado activo en la
estrategia `hybrid`.

#### Scenario: Introspección caída
- **WHEN** con `hybrid` llega un token bien firmado, API-Auth no responde y el modo degradado está apagado
- **THEN** la respuesta es 503 y la petición no llega al controlador

#### Scenario: Introspección responde 500
- **WHEN** la introspección responde 500
- **THEN** se lanza `AuthServerUnavailable`

### Requirement: Valores de configuración vacíos usan el valor por defecto
Un valor de Auth vacío, nulo o no numérico MUST usar su valor por defecto: `timeout` 5, `connect_timeout`
2, `circuit_breaker.failures` 5, `circuit_breaker.cooldown` 30, `cache.public_key_ttl` 86400,
`cache.introspection_ttl` 30, `cache.service_token_margin` 60, `cache.prefix` `ubo-auth:`, `strategy`
`hybrid` y `leeway` 10. Los mismos MUST aplicarse si el valor es `<= 0`, salvo `leeway`, que acepta `0`.
`degraded_mode` vacío MUST ser `false`.

#### Scenario: Timeout vacío en el .env
- **WHEN** el `.env` tiene `UBO_AUTH_TIMEOUT=` sin valor
- **THEN** las llamadas a API-Auth usan un timeout de 5 segundos

#### Scenario: Timeout de conexión vacío
- **WHEN** el `.env` tiene `UBO_AUTH_CONNECT_TIMEOUT=` sin valor
- **THEN** las llamadas a API-Auth usan un timeout de conexión de 2 segundos

#### Scenario: TTL de la clave en cero
- **WHEN** la configuración tiene `cache.public_key_ttl = 0`
- **THEN** la clave pública se guarda en caché 86400 segundos

#### Scenario: Tolerancia de reloj en cero
- **WHEN** el `.env` tiene `UBO_AUTH_LEEWAY=0`
- **THEN** la vigencia de los tokens se revisa sin tolerancia

#### Scenario: Estrategia vacía
- **WHEN** el `.env` tiene `UBO_AUTH_STRATEGY=` sin valor
- **THEN** se usa la estrategia `hybrid`

#### Scenario: Estrategia desconocida
- **WHEN** el `.env` tiene `UBO_AUTH_STRATEGY=oauth`
- **THEN** sigue fallando con `InvalidArgumentException` al resolver el validador

#### Scenario: Configuración publicada sin las claves nuevas
- **WHEN** la aplicación publicó `ubo-auth.php` con la versión 3.0.0
- **THEN** el circuit breaker funciona con 5 fallos y 30 segundos, y el modo degradado está apagado

### Requirement: Comando de diagnóstico
El comando `php artisan ubo:auth:check` SHALL mostrar la estrategia, la URL de API-Auth, el nombre de la
API, el estado del circuito hacia API-Auth y si el modo degradado está activo, verificar que la clave
pública se obtiene, es un PEM válido y, con `APP_ENV=production`, no es la de prueba, y pedir un token
propio de scope `introspect` si hay credenciales. Sus llamadas MUST ir siempre a API-Auth, aunque el
circuito esté abierto, y su resultado MUST NOT cambiar el estado del circuito. MUST terminar con error si
una revisión falla o si la estrategia no es `jwt` y faltan credenciales.

#### Scenario: Todo configurado
- **WHEN** se ejecuta el comando con URL, credenciales válidas y API-Auth disponible
- **THEN** cada revisión muestra OK y el comando termina con éxito

#### Scenario: Estrategia hybrid sin credenciales
- **WHEN** la estrategia es `hybrid` y no hay `UBO_AUTH_CLIENT_ID` o `UBO_AUTH_CLIENT_SECRET`
- **THEN** el comando informa que la estrategia necesita credenciales y termina con error

#### Scenario: Estrategia jwt sin credenciales
- **WHEN** la estrategia es `jwt`, la clave pública es válida y no hay credenciales
- **THEN** el comando informa que las credenciales no son necesarias y termina con éxito

#### Scenario: Clave de prueba en producción
- **WHEN** se ejecuta el comando con `APP_ENV=production` y la clave pública es la de prueba
- **THEN** la revisión de la clave pública muestra FAIL indicando que es la clave de prueba y el comando termina con error

#### Scenario: Circuito abierto
- **WHEN** el circuito hacia API-Auth está abierto y API-Auth ya responde
- **THEN** el comando muestra el circuito como abierto, sus revisiones llegan a API-Auth y muestran OK

## ADDED Requirements

### Requirement: Circuit breaker hacia API-Auth
Las llamadas a API-Auth (clave pública, introspección y token propio) SHALL compartir un circuito
guardado en el almacén de caché de Auth. Cuentan como fallo un error de conexión, un timeout y un 5xx; un
2xx o 4xx MUST cerrar el circuito y reiniciar la cuenta. Tras `UBO_AUTH_CIRCUIT_FAILURES` fallos seguidos
(5) el circuito MUST abrirse durante `UBO_AUTH_CIRCUIT_COOLDOWN` segundos (30).

#### Scenario: Apertura del circuito
- **WHEN** cinco llamadas seguidas a API-Auth fallan por timeout
- **THEN** el circuito hacia API-Auth queda abierto por 30 segundos

#### Scenario: Errores del cliente no abren el circuito
- **WHEN** la introspección responde 401 o 400 varias veces seguidas
- **THEN** el circuito sigue cerrado

#### Scenario: Circuito compartido entre workers
- **WHEN** el almacén de caché es compartido (Redis o base de datos) y un worker abre el circuito
- **THEN** los demás workers que usan ese almacén también fallan de inmediato

#### Scenario: Apertura y cierre en el log
- **WHEN** el circuito hacia API-Auth se abre o se cierra
- **THEN** se registra una sola línea de log (`warning` al abrir, `info` al cerrar), no una por petición

### Requirement: Llamadas a API-Auth con el circuito abierto
Mientras el circuito está abierto, ninguna llamada SHALL enviarse a API-Auth: la validación MUST lanzar
`AuthServerUnavailable` y la obtención del token propio `ServiceTokenException`. Pasado el enfriamiento,
un solo proceso MUST hacer una llamada de prueba: si funciona, el circuito se cierra; si falla, se abre por
otro periodo completo.

#### Scenario: API-Auth caído
- **WHEN** el circuito está abierto y llega un token que no está en caché de introspección
- **THEN** la respuesta es 503 de inmediato, sin llamar a API-Auth

#### Scenario: Recuperación
- **WHEN** pasan 30 segundos con el circuito abierto y la llamada de prueba responde 200
- **THEN** el circuito se cierra y las siguientes validaciones consultan a API-Auth normalmente

#### Scenario: Prueba fallida
- **WHEN** pasan 30 segundos con el circuito abierto y la llamada de prueba falla
- **THEN** el circuito se abre otros 30 segundos

#### Scenario: Tokens que no necesitan a API-Auth
- **WHEN** el circuito está abierto, la estrategia es `jwt` y la clave pública está en caché
- **THEN** los tokens bien firmados y vigentes se aceptan

### Requirement: Modo degradado en la estrategia híbrida
Con `UBO_AUTH_DEGRADED_MODE=true` y la estrategia `hybrid`, si la introspección lanza
`AuthServerUnavailable`, el token SHALL aceptarse con los datos de la validación local, y MUST registrarse
un `warning` que indique que se aceptó sin consultar la revocación. El modo degradado MUST estar apagado
por defecto y MUST NOT aplicarse a la estrategia `introspection`, ni a un token inactivo, ni a uno
rechazado por la validación local.

#### Scenario: API-Auth caído con modo degradado
- **WHEN** el modo degradado está activo, la estrategia es `hybrid`, llega un token bien firmado y vigente y la introspección no responde
- **THEN** el token se acepta y queda un `warning` en el log

#### Scenario: Token revocado con API-Auth disponible
- **WHEN** el modo degradado está activo y la introspección responde `{"active": false}`
- **THEN** el token se rechaza con 401 y motivo `inactive_token`

#### Scenario: Token falsificado con modo degradado
- **WHEN** el modo degradado está activo, API-Auth no responde y llega un token mal firmado
- **THEN** se rechaza con 401 sin consultar a API-Auth

#### Scenario: Estrategia introspection
- **WHEN** el modo degradado está activo, la estrategia es `introspection` y API-Auth no responde
- **THEN** la respuesta es 503
