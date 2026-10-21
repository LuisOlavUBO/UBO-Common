# Módulo ApiClient

Permite **llamar a las APIs de la UBO sin manejar tokens**. El desarrollador indica a qué API llama y
qué endpoint usa. La librería pide el token a API-Auth, lo cachea, lo renueva, reintenta y convierte
los errores.

```php
use UBO\Common\ApiClient\UboApi;

$comunas = UboApi::call('maestros')
    ->get('/api/v1/geografia/regiones/13/comunas')
    ->json('data');
```

## 1. Configuración en el sistema consumidor

```dotenv
UBO_AUTH_URL=https://api-auth.ubo.cl
UBO_AUTH_CLIENT_ID=<credencial del sistema en API-Auth>
UBO_AUTH_CLIENT_SECRET=<secreto>

UBO_API_MAESTROS_URL=https://maestros.ubo.cl
```

El catálogo de APIs está en `config/ubo-apis.php` (publicable con
`php artisan vendor:publish --tag=ubo-apis-config`):

```php
'apis' => [
    'maestros' => [
        'url' => env('UBO_API_MAESTROS_URL'),
        'scopes' => env('UBO_API_MAESTROS_SCOPES', 'geografia:leer sistemas:leer homologacion:leer'),
        // opcional: 'timeout', 'connect_timeout', 'retries', 'retry_delay', 'headers'
    ],
    // agregar una API = agregar una entrada
],
```

`scopes` son los que se piden a API-Auth para esa API. Conviene pedir solo los que el sistema usa.

## 2. Llamadas síncronas: `call`

```php
UboApi::call('maestros')->get('/api/v1/geografia/paises');                        // GET
UboApi::call('maestros')->get('/api/v1/geografia/paises', ['incluir_inactivos' => 1]); // query
UboApi::call('maestros')->post('/api/v1/homologaciones/traducir-lote', [...]);     // POST JSON
UboApi::call('maestros')->put(...); ->patch(...); ->delete(...); ->head(...);
```

- Cada método devuelve un `Illuminate\Http\Client\Response` exitoso (2xx): `->json()`,
  `->json('data')`, `->json('meta.total')`, `->status()`, `->header('ETag')`...
- Antes del verbo se puede encadenar cualquier método de configuración del cliente HTTP de Laravel:

  ```php
  UboApi::call('maestros')->withHeaders(['If-None-Match' => $etag])->timeout(30)->get(...);
  ```

## 3. Llamadas asíncronas: `callAsync` y `all`

`callAsync` inicia la petición de inmediato y devuelve un `UboPromise`, el equivalente a un `Task`
de C#. `wait()` espera el resultado, como `await`.

```php
$promise = UboApi::callAsync('maestros')->get('/api/v1/geografia/paises');
// ... otro trabajo ...
$paises = $promise->wait()->json('data');
```

Varias llamadas **en paralelo**: el tiempo total es el de la más lenta, no la suma.

```php
['paises' => $paises, 'comunas' => $comunas] = UboApi::all([
    'paises' => UboApi::callAsync('maestros')->get('/api/v1/geografia/paises'),
    'comunas' => UboApi::callAsync('maestros')->get('/api/v1/geografia/regiones/13/comunas'),
]);
```

`all()` conserva las claves del arreglo y lanza la primera `UboApiException` que encuentre.

> PHP no tiene `async/await` como C#: el proceso igual espera en `wait()`. La ventaja real es hacer
> **varias llamadas al mismo tiempo**. Para trabajo "en segundo plano" (no esperar la respuesta)
> se usan las colas de Laravel, no `callAsync`.

## 4. Qué hace la librería en cada llamada

1. Toma el token de la caché o lo pide a API-Auth (client credentials con los `scopes` de la API).
   Un candado de caché evita que varios procesos pidan el mismo token a la vez.
2. Arma la petición: URL base, `Authorization: Bearer`, `Accept: application/json`, timeouts y headers.
3. **401**: descarta el token, pide uno nuevo y reintenta una vez, sin espera.
4. **Error de conexión**, o **5xx en un método idempotente** (GET, HEAD, PUT, DELETE, OPTIONS):
   reintenta hasta `retries` veces (2 por defecto), esperando `retry_delay` × intento ms.
   POST y PATCH no se reintentan ante un 5xx, para no duplicar operaciones.
5. **4xx o 5xx final**: lanza `UboApiException`.

## 5. Errores

```php
use UBO\Common\ApiClient\Exceptions\UboApiException;

try {
    UboApi::call('maestros')->get('/api/v1/geografia/comunas/99999');
} catch (UboApiException $e) {
    $e->api;                  // 'maestros'
    $e->status;               // 404 (null si no hubo respuesta)
    $e->errorCode;            // 'NO_ENCONTRADO'  (campo "error" del RFC 9457)
    $e->title;                // título del problema
    $e->detail;               // detalle legible
    $e->response;             // Response completo, si hubo
    $e->isConnectionError();  // true si la API no respondió
    $e->getMessage();         // "[maestros] HTTP 404 NO_ENCONTRADO: ..."
}
```

| Excepción | Cuándo |
|---|---|
| `UboApiException` | La API respondió 4xx/5xx, no respondió, o API-Auth no entregó el token |
| `UnknownApi` | El nombre no está en `config/ubo-apis.php` o no tiene URL (indica qué variable falta) |

## 6. Tests en el sistema consumidor

```php
use Illuminate\Support\Facades\Http;
use UBO\Common\ApiClient\UboApi;

UboApi::fake([
    'maestros/api/v1/geografia/*' => ['data' => [['cut' => '13101', 'nombre' => 'Santiago']]],
    'maestros/api/v1/sistemas/*' => Http::response(['error' => 'NO_ENCONTRADO'], 404),
]);
```

- Las claves empiezan con el nombre de la API.
- `UboApi::fake()` simula también a API-Auth y funciona aunque no haya ninguna variable configurada.
- Las aserciones de Laravel siguen disponibles: `Http::assertSent(...)`, `Http::assertSentCount(...)`.
