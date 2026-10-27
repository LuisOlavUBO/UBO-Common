# Módulo Health

El endpoint de salud que exponen las APIs de la UBO para los orquestadores, balanceadores y los
healthchecks de nginx. Revisa que la base de datos responda a un `SELECT 1`.

```
GET /api/<nombre>/v1/salud   (sin autenticación; por ejemplo /api/maestros/v1/salud)

200 {"estado":"OK","base_datos":"OK"}
503 {"estado":"ERROR","base_datos":"NO_DISPONIBLE"}
```

**La forma de la respuesta es parte del contrato**: los healthchecks dependen de ella, así que no
cambia sin una versión MAJOR.

El módulo es **pasivo**: no registra rutas.

## 1. Instalación en la API

En `routes/api.php`, fuera de cualquier middleware de autenticación. La API publica sus rutas bajo
`/api/<nombre>` (`apiPrefix` en `bootstrap/app.php`), así que `v1/salud` queda en `/api/<nombre>/v1/salud`:

```php
use UBO\Common\Health\Http\Controllers\HealthController;

Route::get('v1/salud', HealthController::class);
```

Por defecto revisa la conexión por defecto de la aplicación. Para revisar otra:

```dotenv
UBO_HEALTH_CONNECTION=sqlsrv_maestros
```

## 2. Migrar desde el controlador propio

| Antes | Ahora |
|---|---|
| `App\Shared\Http\Controllers\SaludController` (API-Maestros) | `UBO\Common\Health\Http\Controllers\HealthController` |
| `App\Http\Controllers\HealthController` (API-Auth) | `UBO\Common\Health\Http\Controllers\HealthController` |
| `VerificadorBaseDatos::disponible()` / `DatabaseHealth::isAvailable()` | `UBO\Common\Health\DatabaseHealth::isAvailable()` |

La respuesta es la misma en las dos APIs.

## 3. Tests

Para simular una base de datos caída:

```php
use UBO\Common\Health\DatabaseHealth;

$this->mock(DatabaseHealth::class)->shouldReceive('isAvailable')->andReturnFalse();

$this->getJson('/api/maestros/v1/salud')->assertStatus(503);
```
