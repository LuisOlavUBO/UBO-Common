# Módulo Tracing

Permite **seguir una llamada a través de todos los servicios de la UBO** que recorre. Cada petición
lleva un identificador de traza (`trace_id`) que se comparte en toda la cadena. Ese identificador
aparece en los logs de cada servicio y se devuelve a quien llamó.

```
Sistema X ──traceparent──► API 1 ──traceparent──► API 2
log X:     trace 4bf9…      log API 1: trace 4bf9…     log API 2: trace 4bf9…
                                       span a1b2…                span c3d4…  (padre: a1b2…)
```

Buscando el `trace_id` en los logs se ve la cadena completa, y con `span_id` / `parent_span_id` se
sabe quién llamó a quién.

Usa el estándar **W3C Trace Context** (header `traceparent`), el mismo de OpenTelemetry, Jaeger,
Grafana Tempo, Elastic APM y Azure App Insights. Si más adelante se instala una de esas herramientas,
el formato ya es compatible.

**No requiere configuración:** el módulo viene activado y funciona apenas se actualiza la librería.

## 1. Qué hace

| Momento | Qué pasa |
|---|---|
| Llega una petición | Si trae un `traceparent` válido, continúa esa traza; si no trae o es inválido, empieza una nueva. Crea un span propio para la petición. |
| Mientras se atiende | `trace_id`, `span_id` y `parent_span_id` quedan en el `Context` de Laravel, así que **aparecen en cada línea de log** sin configurar nada. |
| Se llama a otra API con `UboApi` | Se envía `traceparent` con la traza actual y el span de esta petición como padre (también en `callAsync` y en los reintentos). |
| Se llama a API-Auth (token, introspección, clave pública) | También se envía `traceparent`. |
| Se despacha un job a la cola | El job lleva la traza y, al ejecutarse, abre su propio span, hijo del que lo despachó. |
| Se responde | La respuesta trae el header `X-Trace-Id` con el `trace_id`, también en las respuestas de error. |
| Comandos y tareas programadas | La traza empieza con la primera llamada a otra API. |

## 2. Usar el trace id en la aplicación

```php
use Illuminate\Support\Facades\Context;

Context::get('trace_id');   // también span_id y parent_span_id
```

O con el contrato del módulo:

```php
use UBO\Common\Tracing\Contracts\TraceContext;

app(TraceContext::class)->traceId();
```

Por ejemplo, para incluirlo en el cuerpo de las respuestas de error y que el usuario pueda informarlo.

## 3. Configuración opcional

```dotenv
UBO_COMMON_TRACING=true              # false: desactiva el módulo
UBO_TRACING_RESPONSE_HEADER=X-Trace-Id   # vacío: no se envía el header de respuesta
UBO_TRACING_TRUST_INCOMING=true      # false: cada petición empieza una traza nueva
```

`UBO_TRACING_TRUST_INCOMING=false` es para aplicaciones expuestas a llamadas de fuera de la UBO,
donde no conviene que un tercero fije el `trace_id`.

## 4. Sistemas que no usan UBO.Common

Para que la traza empiece en ellos, deben enviar el header `traceparent` en sus llamadas:

```
traceparent: 00-<trace id: 32 hex>-<span id: 16 hex>-01
```

Si no lo envían, la traza empieza en la primera API que use UBO.Common. Para ver en sus logs la
traza de una llamada, pueden leer el header `X-Trace-Id` de la respuesta.

## 5. Seguridad

- El `traceparent` llega de quien llama, así que se valida estrictamente según el estándar: un valor
  inválido se descarta y se empieza una traza nueva. No se puede inyectar texto en los logs.
- La traza sirve **solo para seguir y auditar llamadas, no para autorizar**. Cualquiera puede inventar
  un header; los permisos siguen dependiendo del token y sus scopes.

## 6. Tests

No hace falta un fake: basta con enviar el header en la petición de prueba y revisar lo que se envía
con `Http::assertSent()`:

```php
$this->getJson('/ruta', ['traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01'])
    ->assertHeader('X-Trace-Id', '4bf92f3577b34da6a3ce929d0e0e4736');
```
