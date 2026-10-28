# Changelog

Formato basado en [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/) y
[versionado semántico](https://semver.org/lang/es/):

- **MAJOR**: rompe compatibilidad (las aplicaciones tienen que cambiar su código o configuración).
- **MINOR**: agrega funcionalidad compatible (un módulo nuevo, una opción nueva).
- **PATCH**: corrige errores sin cambiar el comportamiento esperado.

## [Unreleased]

### Changed

- **Auth**: las rutas de los endpoints de API-Auth las fija el paquete y la clave `endpoints` de un
  `config/ubo-auth.php` publicado se ignora, para que una versión anterior del archivo no deje rutas
  viejas. Para reemplazar una ruta se usan las variables nuevas `UBO_AUTH_ENDPOINT_TOKEN`,
  `UBO_AUTH_ENDPOINT_INTROSPECT` y `UBO_AUTH_ENDPOINT_PUBLIC_KEY` (vacías, la ruta del paquete).

### Fixed

- **Auth**: una variable definida vacía en el `.env` (por ejemplo `UBO_AUTH_TIMEOUT=`), un valor no
  numérico o un `0` usan el valor por defecto. Antes quedaban en `0`: sin timeout hacia API-Auth, o una
  descarga de la clave pública en cada petición. `UBO_AUTH_LEEWAY=0` sigue siendo válido.
- **Config**: la configuración publicada de cualquier módulo se fusiona en profundidad con la del
  paquete, así que un archivo publicado con una versión anterior recibe las claves anidadas nuevas (por
  ejemplo `ubo-auth.cache.service_token_margin`). Las listas se reemplazan completas.

- **ApiClient**: POST y PATCH ya no se reintentan ante un error de conexión cuando la conexión con la
  API llegó a establecerse (por ejemplo, un timeout de lectura), porque la API pudo haber recibido la
  petición y el reintento duplicaba la operación. Se siguen reintentando si la petición no llegó a
  salir (host no resuelto, conexión rechazada, timeout de conexión o fallo del handshake TLS). Los
  métodos idempotentes no cambian.

## [3.0.0] - 2026-10-27

Versión MAJOR: requiere API-Auth 3.0.0, que publica todas sus rutas bajo `/api/auth`. Con API-Auth
2.x los tokens y la validación fallan. Las aplicaciones actualizan `ubo/common` junto con el
despliegue de API-Auth 3.0.0; `UBO_AUTH_URL` sigue siendo solo el host de API-Auth y no cambia.

### Changed

- **Auth**: las rutas por defecto de `ubo-auth.endpoints` pasan a las de API-Auth 3.0.0:
  `token` `/oauth/token` → `/api/auth/oauth/token`, `introspect` `/api/v1/auth/introspect` →
  `/api/auth/v1/introspect` y `public_key` `/api/v1/auth/public-key` → `/api/auth/v1/public-key`.
  Una aplicación que publicó `config/ubo-auth.php` tiene que actualizar esas rutas a mano.
- **Docs**: los ejemplos usan las URLs con el nombre de la API (`/api/maestros/v1/...`), y el módulo
  Health documenta `v1/salud` bajo el prefijo de cada API (`/api/<nombre>/v1/salud`).

## [2.3.1] - 2026-10-23

Versión PATCH: compatible con la 2.3. Las aplicaciones solo tienen que ejecutar
`composer update ubo/common`.

### Fixed

- **Auth**: con la estrategia `jwt` o `hybrid`, un token con una firma inválida que llegaba mientras
  API-Auth no respondía borraba de la caché la clave pública que funcionaba. Desde ese momento todos
  los tokens válidos recibían `503 AuthServerUnavailable` hasta que API-Auth volvía. Ahora
  `HttpPublicKeyProvider::refresh()` reemplaza la clave solo cuando la nueva se descargó bien; si la
  descarga falla, conserva la anterior y el token con firma inválida se rechaza con `401`, como con
  API-Auth disponible.

## [2.3.0] - 2026-10-23

Versión MINOR: compatible con la 2.2. Agrega tres módulos que hoy están duplicados en API-Auth y
API-Maestros. `Problems` y `Health` vienen activados pero son pasivos (no hacen nada hasta que la
aplicación los usa); `SqlServer` viene apagado. Las aplicaciones no tienen que cambiar nada.

### Added

- **Problems**: módulo nuevo. `ProblemRenderer` presenta los errores de las rutas `api/*` en RFC 9457
  (`application/problem+json`) con un código estable en `error` (`NO_AUTENTICADO`,
  `SCOPE_INSUFICIENTE`, `ACCESO_DENEGADO`, `VALIDACION`, `LIMITE_EXCEDIDO`, `RUTA_NO_ENCONTRADA`,
  `METODO_NO_PERMITIDO`, `AUTENTICACION_NO_DISPONIBLE`, `ERROR_HTTP`, `ERROR_INTERNO`), con los mismos
  títulos y detalles que responden hoy las APIs. Se registra en `bootstrap/app.php` con
  `$exceptions->render(new ProblemRenderer)`. Se configura con `UBO_COMMON_PROBLEMS` y
  `UBO_PROBLEMS_ROUTES`.
- **Problems**: `DomainProblem`, clase base de los errores de negocio (`code()`, `status()`,
  `title()`, `extensions()`), y `Problem`, para que una aplicación arme con la misma forma los errores
  que el renderizador no conoce.
- **Health**: módulo nuevo. `HealthController` responde `{"estado":"OK","base_datos":"OK"}` o 503
  `{"estado":"ERROR","base_datos":"NO_DISPONIBLE"}` según `DatabaseHealth`. La aplicación agrega la
  ruta. Se configura con `UBO_COMMON_HEALTH` y `UBO_HEALTH_CONNECTION`.
- **SqlServer**: módulo nuevo, **apagado por defecto**. Con `UBO_COMMON_SQLSERVER=true` ejecuta
  `SET DATEFORMAT ymd` en cada conexión `sqlsrv` al abrirse y en las que ya estaban abiertas. El
  formato se cambia con `UBO_SQLSERVER_DATE_FORMAT`.
- Dependencias `illuminate/auth`, `illuminate/database`, `illuminate/translation` e
  `illuminate/validation`.

### Changed

- `docs/framework.md`: los textos que forman parte del contrato de las APIs (códigos de error,
  `title` y `detail`) van en español; la regla del inglés aplica al código, la configuración y los
  mensajes de excepción y de log. `Responses` y `HttpCache` siguen como candidatos.

## [2.2.0] - 2026-10-23

Versión MINOR: compatible con la 2.1. Agrega el módulo `Tracing`, activado por defecto; las
aplicaciones no tienen que cambiar nada.

### Added

- **Tracing**: módulo nuevo con W3C Trace Context. Un middleware global continúa el `traceparent` de
  quien llama (o empieza una traza nueva si no viene o es inválido), deja `trace_id`, `span_id` y
  `parent_span_id` en el `Context` de Laravel (aparecen en los logs y viajan con los jobs) y devuelve
  el `trace_id` en el header `X-Trace-Id`. Se configura con `UBO_COMMON_TRACING`,
  `UBO_TRACING_RESPONSE_HEADER` y `UBO_TRACING_TRUST_INCOMING`.
- **Tracing**: contrato `TraceContext` (`traceId()`, `spanId()`, `headers()`).
- **ApiClient**: las llamadas de `UboApi::call()` y `callAsync()` envían `traceparent`, con el span de
  la petición actual como padre.
- **Auth**: las llamadas a API-Auth (token, introspección y clave pública) envían `traceparent`.
- Dependencia `illuminate/log`, donde vive `Context`.

## [2.1.1] - 2026-10-22

### Fixed

- **Auth**: `TokenFactory::forUser()` también emite los tokens para la API configurada en
  `ubo-auth.api` (y acepta `api:`). Con `UBO_AUTH_API` definida, un token de sistema simulado con un
  `sub` que solo difiere en mayúsculas del client id era rechazado en los tests por `wrong_audience`.

## [2.1.0] - 2026-10-22

Versión MINOR: compatible con la 2.0. Para rechazar tokens emitidos para otra API hace falta la
versión de API-Auth que pone el nombre de la API en el `aud`.

### Added

- **Auth**: `UBO_AUTH_API` (`ubo-auth.api`), el nombre de esta API en API-Auth. Con ella definida,
  el middleware `ubo.auth` rechaza (401 `wrong_audience`) los tokens de sistema cuyo `aud` no nombra
  a esta API, aunque traigan un scope con el mismo nombre. Sin ella no cambia nada.
- **Auth**: `VerifiedToken::$audiences` y `VerifiedToken::isFor()`. La introspección conserva el
  `aud` que devuelve API-Auth.
- **Auth**: `TokenFactory::forClient()` emite los tokens para la API configurada en `ubo-auth.api`
  y acepta `api:` para simular tokens de otra API.
- **Auth**: `ubo:auth:check` muestra el nombre de la API configurado.

## [2.0.0] - 2026-10-21

Versión MAJOR: cambia la forma de llamar a las APIs. Requiere la versión de API-Auth que
acepta el parámetro `api` en `/oauth/token`.

### Changed

- **ApiClient**: `UboApi::call()` y `UboApi::callAsync()` reciben el nombre de la API en API-Auth y
  su URL: `UboApi::call('api-maestros', config('services.maestros.url'))`. Common ya no tiene un
  catálogo de APIs; cada sistema guarda las URLs que usa en su propia configuración.
- **ApiClient**: cada API recibe un token emitido solo para ella. Common lo pide con `api=<nombre>`
  y API-Auth le pone los scopes que el sistema tiene en esa API; ya no se declaran scopes. Hay un
  token en caché por API.
- **ApiClient**: `UboApi::fake()` usa como clave el nombre de la API en API-Auth
  (`'api-maestros/api/v1/...'`) o una URL completa.
- **Auth**: `ServiceTokenProvider::token()` y `forget()` aceptan el parámetro opcional `$api`.
- **Auth**: cuando API-Auth rechaza un pedido de token, el mensaje incluye su `hint` (por ejemplo,
  qué scope no es válido).

### Removed

- **ApiClient**: el arreglo `apis` de `config/ubo-apis.php`, la variable `UBO_API_MAESTROS_URL` y la
  excepción `UnknownApi`. Una llamada sin nombre de API o sin URL lanza `InvalidArgumentException`.

## [1.0.1] - 2026-10-21

### Fixed

- **Auth**: un token de sistema (client credentials) cuyo `sub` es el client_id con otras
  mayúsculas/minúsculas (SQL Server devuelve los GUID en mayúsculas) ya no se confunde con un token
  de usuario. Afectaba a las estrategias `jwt`, `introspection` y `hybrid`
  (`VerifiedToken::userIdFromSubject()`).

## [1.0.0] - 2026-10-21

### Added

- Estructura modular: `UboCommonServiceProvider`, `Support\ModuleServiceProvider` y `config/ubo-common.php`.
- Módulo **Auth**: middleware `ubo.auth` que valida tokens de API-Auth con las estrategias `jwt`,
  `introspection` y `hybrid`; acepta solo tokens M2M por defecto; incluye `VerifiedToken`,
  `ServiceTokenProvider`, el comando `ubo:auth:check` y `Testing\TokenFactory`.
- Módulo **ApiClient**: `UboApi::call()`, `callAsync()` y `all()`, con token automático, renovación
  ante 401, reintentos, errores como `UboApiException` y `UboApi::fake()` para tests.
