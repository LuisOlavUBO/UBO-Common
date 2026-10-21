# UBO.Common (`ubo/common`)

Librería PHP/Laravel con las piezas comunes de las APIs de la UBO y de los sistemas que las consumen.
**No es una API**: no expone rutas, no tiene base de datos y no se despliega. Se instala con Composer
dentro de cada aplicación (`vendor/ubo/common`) y corre en su mismo proceso.

| Módulo | Para quién | Qué resuelve | Documentación |
|---|---|---|---|
| `Auth` | APIs (ej. Maestros2) | Validar los tokens que emite **API-Auth** con el middleware `ubo.auth` | [docs/modules/auth.md](docs/modules/auth.md) |
| `ApiClient` | Sistemas consumidores (y APIs que llaman a otras) | Llamar a las APIs de la UBO sin manejar tokens: `UboApi::call()` / `callAsync()` | [docs/modules/api-client.md](docs/modules/api-client.md) |

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
php artisan vendor:publish --tag=ubo-apis-config     # catálogo de APIs del módulo ApiClient
```

## Uso rápido

**En una API, para proteger rutas:**

```php
Route::middleware('ubo.auth:geografia:leer')->get('regiones/{cut}', ...);
```

**En un sistema consumidor, para llamar a una API:**

```php
use UBO\Common\ApiClient\UboApi;

$comunas = UboApi::call('maestros')->get('/api/v1/geografia/regiones/13/comunas')->json('data');
```

## Variables de entorno

| Variable | Módulo | Uso |
|---|---|---|
| `UBO_AUTH_URL` | Auth / ApiClient | URL base de API-Auth |
| `UBO_AUTH_CLIENT_ID` / `UBO_AUTH_CLIENT_SECRET` | Auth / ApiClient | Credencial de **esta** aplicación en API-Auth |
| `UBO_AUTH_STRATEGY` | Auth | `jwt`, `introspection` o `hybrid` (por defecto) |
| `UBO_AUTH_PUBLIC_KEY` | Auth | Clave pública fija (PEM o ruta). Vacío: se descarga de API-Auth |
| `UBO_AUTH_ALLOW_USER_TOKENS` | Auth | `false` por defecto: solo tokens M2M |
| `UBO_API_<NOMBRE>_URL` | ApiClient | URL de cada API del catálogo, ej. `UBO_API_MAESTROS_URL` |
| `UBO_COMMON_AUTH` / `UBO_COMMON_API_CLIENT` | — | Activar o desactivar cada módulo (`true` por defecto) |

El resto de los ajustes (TTL de caché, timeouts, reintentos...) está comentado en
`src/Auth/config/ubo-auth.php` y `src/ApiClient/config/ubo-apis.php`.

## Desarrollo de la librería

```bash
composer install
composer test     # Pest + Orchestra Testbench (no necesita API-Auth ni base de datos)
composer lint     # Laravel Pint
```

Versionado semántico: ver [CHANGELOG.md](CHANGELOG.md).
