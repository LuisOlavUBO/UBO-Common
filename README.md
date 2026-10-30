# UBO.Common (`ubo/common`)

Librería PHP/Laravel con las piezas comunes de las APIs de la UBO y de los sistemas que las consumen.
**No es una API**: no tiene base de datos y no se despliega, y solo registra rutas si se activa el módulo `Login`. Se instala con Composer
dentro de cada aplicación (`vendor/ubo/common`) y corre en su mismo proceso.

| Módulo | Para quién | Qué resuelve | Documentación |
|---|---|---|---|
| `Auth` | APIs (ej. Maestros2) | Validar los tokens que emite **API-Auth** con el middleware `ubo.auth` | [docs/modules/auth.md](docs/modules/auth.md) |
| `ApiClient` | Sistemas consumidores (y APIs que llaman a otras) | Llamar a las APIs de la UBO sin manejar tokens: `UboApi::call()` / `callAsync()` | [docs/modules/api-client.md](docs/modules/api-client.md) |
| `Tracing` | Todas las aplicaciones | Seguir una llamada por todos los servicios que recorre (W3C `traceparent`, `trace_id` en los logs) | [docs/modules/tracing.md](docs/modules/tracing.md) |
| `Problems` | APIs | Errores en RFC 9457 (`application/problem+json`) con código estable en `error`: `ProblemRenderer` y `DomainProblem` | [docs/modules/problems.md](docs/modules/problems.md) |
| `Health` | APIs | Endpoint de salud `GET /api/<nombre>/v1/salud` que revisa la base de datos: `HealthController` | [docs/modules/health.md](docs/modules/health.md) |
| `SqlServer` | Aplicaciones con SQL Server | `SET DATEFORMAT ymd` en cada conexión `sqlsrv` (**apagado** por defecto) | [docs/modules/sqlserver.md](docs/modules/sqlserver.md) |
| `Login` | Monolitos Laravel | Login de personas en **IAM** (Authorization Code + PKCE), usuario con roles y scopes, `@can` y logout (**apagado** por defecto) | [docs/modules/login.md](docs/modules/login.md) |

Reglas para agregar módulos nuevos: [docs/framework.md](docs/framework.md).

## Requisitos

- PHP 8.3+
- Laravel 12 o 13

## Instalación

Mientras se desarrolla, cada aplicación apunta a esta carpeta (repositorio Composer de tipo `path`):

```json
"repositories": [
    { "type": "path", "url": "../Common" }
],
"require": {
    "ubo/common": "@dev"
}
```

```bash
composer update ubo/common
```

Cuando la librería esté en un repositorio git privado, se reemplaza el `path` por la URL del repositorio
y se fija una versión (`"ubo/common": "^1.0"`).

Laravel registra la librería automáticamente (package discovery). Para copiar un archivo de configuración
a la aplicación y modificarlo:

```bash
php artisan vendor:publish --tag=ubo-common-config   # módulos activos
php artisan vendor:publish --tag=ubo-auth-config     # módulo Auth
php artisan vendor:publish --tag=ubo-apis-config     # timeouts y reintentos del módulo ApiClient
php artisan vendor:publish --tag=ubo-tracing-config  # módulo Tracing
php artisan vendor:publish --tag=ubo-problems-config # módulo Problems
php artisan vendor:publish --tag=ubo-health-config   # módulo Health
php artisan vendor:publish --tag=ubo-sqlserver-config # módulo SqlServer
php artisan vendor:publish --tag=ubo-login-config     # módulo Login
```

## Uso rápido

**En una API, para proteger rutas:**

```php
Route::middleware('ubo.auth:geografia:leer')->get('regiones/{cut}', ...);
```

**En un sistema consumidor, para llamar a una API:**

```php
use UBO\Common\ApiClient\UboApi;

// nombre de la API en API-Auth + su URL (guardada en la config del propio sistema)
$comunas = UboApi::call('api-maestros', config('services.maestros.url'))
    ->get('/api/maestros/v1/geografia/regiones/13/comunas')
    ->json('data');
```

**En una API, para responder errores en RFC 9457 y exponer el endpoint de salud:**

```php
// bootstrap/app.php
$exceptions->render(new \UBO\Common\Problems\ProblemRenderer);

// routes/api.php
Route::get('v1/salud', \UBO\Common\Health\Http\Controllers\HealthController::class);
```

## Variables de entorno

| Variable | Módulo | Uso |
|---|---|---|
| `UBO_AUTH_URL` | Auth / ApiClient | URL base de API-Auth |
| `UBO_AUTH_CLIENT_ID` / `UBO_AUTH_CLIENT_SECRET` | Auth / ApiClient | Credencial de **esta** aplicación en API-Auth |
| `UBO_AUTH_STRATEGY` | Auth | `jwt`, `introspection` o `hybrid` (por defecto) |
| `UBO_AUTH_PUBLIC_KEY` | Auth | Clave pública fija (PEM o ruta). Vacío: se descarga de API-Auth |
| `UBO_AUTH_ALLOW_USER_TOKENS` | Auth | `false` por defecto: solo tokens M2M |
| `UBO_AUTH_API` | Auth | Nombre de esta API en API-Auth. Rechaza tokens de sistema emitidos para otra API |
| `UBO_TRACING_RESPONSE_HEADER` | Tracing | Header de respuesta con el `trace_id` (`X-Trace-Id` por defecto; vacío: no se envía) |
| `UBO_TRACING_TRUST_INCOMING` | Tracing | `true` por defecto: continúa la traza de quien llama |
| `UBO_PROBLEMS_ROUTES` | Problems | Rutas donde responde `ProblemRenderer`, separadas por coma (`api/*` por defecto) |
| `UBO_HEALTH_CONNECTION` | Health | Conexión que revisa el endpoint de salud (vacío: la por defecto) |
| `UBO_SQLSERVER_DATE_FORMAT` | SqlServer | Formato de `SET DATEFORMAT` (`ymd` por defecto) |
| `UBO_LOGIN_PUBLIC_URL` / `UBO_LOGIN_CLIENT_ID` / `UBO_LOGIN_CLIENT_SECRET` | Login | Dirección pública de IAM y credenciales del cliente de login de esta aplicación |
| `UBO_COMMON_AUTH` / `UBO_COMMON_API_CLIENT` / `UBO_COMMON_TRACING` / `UBO_COMMON_PROBLEMS` / `UBO_COMMON_HEALTH` | — | Activar o desactivar cada módulo (`true` por defecto) |
| `UBO_COMMON_SQLSERVER` / `UBO_COMMON_LOGIN` | — | Activar los módulos SqlServer y Login (`false` por defecto) |

El resto de los ajustes (TTL de caché, timeouts, reintentos...) está comentado en el archivo de
configuración de cada módulo: `src/<Module>/config/ubo-<module>.php` (`ubo-apis.php` en ApiClient).

## Desarrollo de la librería

```bash
composer install
composer test     # Pest + Orchestra Testbench (no necesita API-Auth ni base de datos)
composer lint     # Laravel Pint
```

Versionado semántico: ver [CHANGELOG.md](CHANGELOG.md).
