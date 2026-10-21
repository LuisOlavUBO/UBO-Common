# Módulo ApiClient

Permite **llamar a cualquier API interna de la UBO sin manejar tokens**. El desarrollador indica a
qué API llama (su nombre en API-Auth), dónde está (su URL) y qué endpoint usa. La librería pide el
token a API-Auth, lo cachea, lo renueva, reintenta y convierte los errores.

```php
use UBO\Common\ApiClient\UboApi;

$comunas = UboApi::call('api-maestros', config('services.maestros.url'))
    ->get('/api/v1/geografia/regiones/13/comunas')
    ->json('data');
```

## 1. Configuración en el sistema consumidor

Las credenciales del sistema en API-Auth:

```dotenv
UBO_AUTH_URL=https://api.ubo.cl/auth
UBO_AUTH_CLIENT_ID=<credencial del sistema en API-Auth>
UBO_AUTH_CLIENT_SECRET=<secreto>
```

La URL de cada API que consume la guarda **el propio sistema**, porque cambia según el ambiente.
Common no tiene un catálogo de APIs: una API nueva no requiere una versión nueva de la librería.

```dotenv
MAESTROS_URL=https://api.ubo.cl/maestros
```

```php
// config/services.php
'maestros' => ['url' => env('MAESTROS_URL')],
```

El primer argumento de `call` es el **nombre de la API en API-Auth** (`php artisan oauth:api ...`).
No cambia entre ambientes, así que va en el código.

## Tokens por API

Cada API recibe un token emitido **solo para ella**: Common pide el token con `api=<nombre>` y
API-Auth le pone todos los scopes que los roles del sistema tienen en esa API. El sistema no declara
scopes; los permisos se administran en API-Auth.

- Hay un token en caché por API. Llamar a otra API no reemplaza el token de la anterior.
- Cada token se pide una vez y se reutiliza hasta poco antes de vencer (60 s antes, por defecto).
- Un token filtrado solo sirve en la API que lo recibió.
- Si el sistema no tiene scopes en esa API, o la API no existe en API-Auth, la llamada falla antes
  de llegar a la API, con un `UboApiException` que indica el motivo.

En `config/ubo-apis.php` (publicable con `php artisan vendor:publish --tag=ubo-apis-config`) solo
quedan los valores por defecto de todas las llamadas: `timeout`, `connect_timeout`, `retries`,
`retry_delay` y `headers`. Para una llamada puntual se encadena el método de Laravel, por ejemplo
`->timeout(30)`.

## 2. Llamadas síncronas: `call`

```php
UboApi::call('api-maestros', $url)->get('/api/v1/geografia/paises');                        // GET
UboApi::call('api-maestros', $url)->get('/api/v1/geografia/paises', ['incluir_inactivos' => 1]); // query
UboApi::call('api-maestros', $url)->post('/api/v1/homologaciones/traducir-lote', [...]);     // POST JSON
UboApi::call('api-maestros', $url)->put(...); ->patch(...); ->delete(...); ->head(...);
```

- Cada método devuelve un `Illuminate\Http\Client\Response` exitoso (2xx): `->json()`,
  `->json('data')`, `->json('meta.total')`, `->status()`, `->header('ETag')`...
- Antes del verbo se puede encadenar cualquier método de configuración del cliente HTTP de Laravel:

  ```php
  UboApi::call('api-maestros', $url)->withHeaders(['If-None-Match' => $etag])->timeout(30)->get(...);
  ```

## 3. Llamadas asíncronas: `callAsync` y `all`

`callAsync` inicia la petición de inmediato y devuelve un `UboPromise`, el equivalente a un `Task`
de C#. `wait()` espera el resultado, como `await`.

```php
$promise = UboApi::callAsync('api-maestros', $url)->get('/api/v1/geografia/paises');
// ... otro trabajo ...
$paises = $promise->wait()->json('data');
```

Varias llamadas **en paralelo**: el tiempo total es el de la más lenta, no la suma.

```php
['paises' => $paises, 'comunas' => $comunas] = UboApi::all([
    'paises' => UboApi::callAsync('api-maestros', $url)->get('/api/v1/geografia/paises'),
    'comunas' => UboApi::callAsync('api-maestros', $url)->get('/api/v1/geografia/regiones/13/comunas'),
]);
```

`all()` conserva las claves del arreglo y lanza la primera `UboApiException` que encuentre.

> PHP no tiene `async/await` como C#: el proceso igual espera en `wait()`. La ventaja real es hacer
> **varias llamadas al mismo tiempo**. Para trabajo "en segundo plano" (no esperar la respuesta)
> se usan las colas de Laravel, no `callAsync`.

## 4. Qué hace la librería en cada llamada

1. Toma el token de esa API de la caché o lo pide a API-Auth (client credentials con `api=<nombre>`).
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
    UboApi::call('api-maestros', $url)->get('/api/v1/geografia/comunas/99999');
} catch (UboApiException $e) {
    $e->api;                  // 'api-maestros'
    $e->status;               // 404 (null si no hubo respuesta)
    $e->errorCode;            // 'NO_ENCONTRADO'  (campo "error" del RFC 9457)
    $e->title;                // título del problema
    $e->detail;               // detalle legible
    $e->response;             // Response completo, si hubo
    $e->isConnectionError();  // true si la API no respondió
    $e->getMessage();         // "[api-maestros] HTTP 404 NO_ENCONTRADO: ..."
}
```

| Excepción | Cuándo |
|---|---|
| `UboApiException` | La API respondió 4xx/5xx, no respondió, o API-Auth no entregó el token |
| `InvalidArgumentException` | Se llamó sin nombre de API o sin URL (por ejemplo, falta la variable en el `.env`) |

## 6. Tests en el sistema consumidor

```php
use Illuminate\Support\Facades\Http;
use UBO\Common\ApiClient\UboApi;

UboApi::fake([
    'api-maestros/api/v1/geografia/*' => ['data' => [['cut' => '13101', 'nombre' => 'Santiago']]],
    'api-maestros/api/v1/sistemas/*' => Http::response(['error' => 'NO_ENCONTRADO'], 404),
]);
```

- Las claves empiezan con el nombre de la API. Las llamadas a esa API van a un host falso, sin importar la URL que reciban (puede no estar configurada). También se aceptan URLs completas (`https://...`).
- `UboApi::fake()` simula también a API-Auth y funciona aunque no haya ninguna variable configurada.
- Las aserciones de Laravel siguen disponibles: `Http::assertSent(...)`, `Http::assertSentCount(...)`.
