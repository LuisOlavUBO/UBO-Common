# Módulo ApiClient

Permite **llamar a cualquier API interna de la UBO sin manejar tokens**. El desarrollador indica a
qué API llama (su nombre en API-Auth), dónde está (su URL) y qué endpoint usa. La librería pide el
token a API-Auth, lo cachea, lo renueva, reintenta y convierte los errores.

```php
use UBO\Common\ApiClient\UboApi;

$comunas = UboApi::call('api-maestros', config('services.maestros.url'))
    ->get('/api/maestros/v1/geografia/regiones/13/comunas')
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

### Valores de las llamadas

`config/ubo-apis.php` (publicable con `php artisan vendor:publish --tag=ubo-apis-config`) tiene los
valores de todas las llamadas en `defaults` y, si hace falta, valores propios de una API en
`apis.<nombre>`. Cada clave de `apis.<nombre>` reemplaza a la de `defaults` solo para esa API; las
demás salen de `defaults`.

```php
'defaults' => [
    'timeout' => 10,            // s, cada intento
    'connect_timeout' => 5,     // s, para establecer la conexión
    'deadline' => 30,           // s, la llamada completa (intentos, renovación del token y esperas)
    'retries' => 2,             // reintentos (ver "Qué hace la librería en cada llamada")
    'retry_delay' => 200,       // ms antes del primer reintento; se duplica en cada uno
    'retry_max_delay' => 2000,  // ms, tope de la espera
    'headers' => [],
    'circuit_breaker' => ['enabled' => true, 'failures' => 5, 'cooldown' => 30],
],

'apis' => [
    'api-reportes' => ['timeout' => 30, 'deadline' => 45],
    'api-legado' => ['circuit_breaker' => ['enabled' => false]],
],

'cache_store' => env('UBO_APIS_CACHE_STORE'),  // almacén de los circuitos (vacío: el por defecto)
```

Un valor vacío, no numérico o fuera de rango usa el de `defaults`, y si también es inválido, el del
paquete. Para una llamada puntual se encadena el método de Laravel, por ejemplo `->timeout(30)`, o
`->deadline(5)` para su tiempo total.

## 2. Llamadas síncronas: `call`

```php
UboApi::call('api-maestros', $url)->get('/api/maestros/v1/geografia/paises');                        // GET
UboApi::call('api-maestros', $url)->get('/api/maestros/v1/geografia/paises', ['incluir_inactivos' => 1]); // query
UboApi::call('api-maestros', $url)->post('/api/maestros/v1/homologaciones/traducir-lote', [...]);     // POST JSON
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
$promise = UboApi::callAsync('api-maestros', $url)->get('/api/maestros/v1/geografia/paises');
// ... otro trabajo ...
$paises = $promise->wait()->json('data');
```

Varias llamadas **en paralelo**: el tiempo total es el de la más lenta, no la suma.

```php
['paises' => $paises, 'comunas' => $comunas] = UboApi::all([
    'paises' => UboApi::callAsync('api-maestros', $url)->get('/api/maestros/v1/geografia/paises'),
    'comunas' => UboApi::callAsync('api-maestros', $url)->get('/api/maestros/v1/geografia/regiones/13/comunas'),
]);
```

`all()` conserva las claves del arreglo y lanza la primera `UboApiException` que encuentre.

> PHP no tiene `async/await` como C#: el proceso igual espera en `wait()`. La ventaja real es hacer
> **varias llamadas al mismo tiempo**. Para trabajo "en segundo plano" (no esperar la respuesta)
> se usan las colas de Laravel, no `callAsync`.

## 4. Qué hace la librería en cada llamada

1. Si el **circuito** de esa API está abierto, lanza `UboApiException` de inmediato, sin pedir token
   ni enviar nada (ver "Circuit breaker").
2. Toma el token de esa API de la caché o lo pide a API-Auth (client credentials con `api=<nombre>`).
   Un candado de caché evita que varios procesos pidan el mismo token a la vez.
3. Arma la petición: URL base, `Authorization: Bearer`, `Accept: application/json`, timeouts y headers.
4. **401**: descarta el token, pide uno nuevo y reintenta una vez, sin espera.
5. **Error de conexión**, o **5xx en un método idempotente** (GET, HEAD, PUT, DELETE, OPTIONS):
   reintenta hasta `retries` veces (2 por defecto). Antes del reintento n espera
   `retry_delay` × 2^(n-1) ms (con tope `retry_max_delay`), elegido al azar entre la mitad y el total
   (jitter), para que varios procesos no reintenten todos al mismo tiempo.
   Para no duplicar operaciones, POST y PATCH no se reintentan ante un 5xx, y ante un error de
   conexión solo se reintentan si la petición **no llegó a salir** (no resolvió el host, la conexión
   fue rechazada, se agotó el tiempo de conexión o falló el handshake TLS). Un timeout de lectura en
   un POST o PATCH lanza `UboApiException` de inmediato, porque la API pudo haberlo recibido.
6. **Tiempo total**: la llamada nunca supera `deadline` segundos (30 por defecto), contando intentos y
   esperas. Cada reintento usa como timeout lo que queda, y si ya no cabe (queda menos de 1 s tras la
   espera) no se hace: se lanza `UboApiException` con `isDeadlineExceeded()`. Si el `timeout` de la
   petición es mayor que el `deadline` configurado, el tiempo total pasa a ser ese timeout; con
   `->deadline()` en la llamada, en cambio, siempre manda el `deadline` y acorta el primer intento.
7. **4xx o 5xx final**: lanza `UboApiException`.

En `callAsync`, el tiempo total de cada promesa se cuenta desde que se crea, así que varias llamadas
en paralelo con `all()` terminan, en el peor caso, en el `deadline` de la más lenta.

### Circuit breaker

Cada API tiene su circuito, en la aplicación que llama (la API llamada no se configura ni cambia).
Tras `circuit_breaker.failures` (5) intentos seguidos fallidos (error de conexión, timeout o 5xx, en
cualquier método), el circuito se abre por `circuit_breaker.cooldown` (30) segundos: las llamadas a
esa API fallan de inmediato con `isCircuitOpen()`, sin esperar timeouts ni cargar a una API que ya
tiene problemas. Pasado ese tiempo, una sola llamada prueba la API: si responde, el circuito se
cierra; si no, se abre otros 30 s. Un 2xx, 3xx o 4xx es una respuesta y cierra el circuito; los
circuitos de las demás APIs no se afectan.

El estado vive en el almacén `ubo-apis.cache_store` (`UBO_APIS_CACHE_STORE`; vacío, el por defecto de
la aplicación). Con un almacén compartido (Redis o base de datos), cuando un worker detecta la caída
todos dejan de llamar. Con `file` cada servidor tiene su propio circuito, y con `array` cada proceso:
siguen funcionando, pero detectan la caída más tarde. Si el almacén falla, el circuito se ignora.

Para desactivarlo en una API: `'apis' => ['api-legado' => ['circuit_breaker' => ['enabled' => false]]]`.

Estas protecciones solo aplican a las llamadas hechas con `UboApi::call()` y `callAsync()`. Una
llamada directa con `Http::` o Guzzle, o a un servicio externo a la UBO, no las tiene.

## 5. Errores

```php
use UBO\Common\ApiClient\Exceptions\UboApiException;

try {
    UboApi::call('api-maestros', $url)->get('/api/maestros/v1/geografia/comunas/99999');
} catch (UboApiException $e) {
    $e->api;                  // 'api-maestros'
    $e->status;               // 404 (null si no hubo respuesta)
    $e->errorCode;            // 'NO_ENCONTRADO'  (campo "error" del RFC 9457)
    $e->title;                // título del problema
    $e->detail;               // detalle legible
    $e->response;             // Response completo, si hubo
    $e->isConnectionError();  // true si la API no respondió
    $e->isCircuitOpen();      // true si no se llamó porque el circuito de la API está abierto
    $e->isDeadlineExceeded(); // true si se agotó el tiempo total antes de poder reintentar
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
    'api-maestros/api/maestros/v1/geografia/*' => ['data' => [['cut' => '13101', 'nombre' => 'Santiago']]],
    'api-maestros/api/maestros/v1/sistemas/*' => Http::response(['error' => 'NO_ENCONTRADO'], 404),
]);
```

- Las claves empiezan con el nombre de la API. Las llamadas a esa API van a un host falso, sin importar la URL que reciban (puede no estar configurada). También se aceptan URLs completas (`https://...`).
- `UboApi::fake()` simula también a API-Auth y funciona aunque no haya ninguna variable configurada.
- Después de `UboApi::fake()` todos los circuitos parten cerrados.
- Las aserciones de Laravel siguen disponibles: `Http::assertSent(...)`, `Http::assertSentCount(...)`.
