# tracing Specification

## Purpose

Permite seguir una operación a través de los servicios de la UBO con W3C Trace Context: cada petición
continúa la traza de quien llama o inicia una, los ids quedan en los logs y viajan a las llamadas
salientes y a los jobs.

## Requirements
### Requirement: Traza en cada petición HTTP
El módulo SHALL agregar un middleware global, antes de cualquier otro, que en cada petición continúa la
traza del header `traceparent` recibido o inicia una nueva, creando siempre un span nuevo para la
petición.

#### Scenario: Petición con traceparent válido
- **WHEN** llega `traceparent: 00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01`
- **THEN** la petición usa el trace id `4bf92f3577b34da6a3ce929d0e0e4736`, un span id nuevo y `00f067aa0ba902b7` como span padre

#### Scenario: Petición sin traceparent
- **WHEN** llega una petición sin header `traceparent`
- **THEN** se inicia una traza nueva con trace id de 32 y span id de 16 caracteres hexadecimales

### Requirement: Validación estricta del traceparent recibido
Un `traceparent` SHALL aceptarse solo si cumple `version-traceid-parentid-flags` en hexadecimal (sin
distinguir mayúsculas ni espacios en los extremos). MUST rechazarse la versión `ff`, la versión `00` con
campos extra y los ids en cero. Si se rechaza, se inicia una traza nueva.

#### Scenario: Header inválido
- **WHEN** llega `traceparent: 00-00000000000000000000000000000000-00f067aa0ba902b7-01`
- **THEN** se ignora y se inicia una traza nueva

#### Scenario: Versión futura con campos extra
- **WHEN** llega un `traceparent` de versión `01` con un campo adicional al final
- **THEN** se continúa la traza ignorando el campo adicional

### Requirement: Solo se propaga la marca de muestreo
De los flags recibidos SHALL conservarse solo el bit `sampled`; los demás flags MUST descartarse.

#### Scenario: Flags desconocidos
- **WHEN** llega un `traceparent` con flags `03`
- **THEN** las llamadas salientes envían flags `01`

### Requirement: Confiar o no en la traza de quien llama
Con `UBO_TRACING_TRUST_INCOMING=false`, cada petición MUST iniciar una traza nueva aunque traiga un
`traceparent` válido. Por defecto (`true`) se continúa la traza recibida.

#### Scenario: Aplicación expuesta fuera de la UBO
- **WHEN** `UBO_TRACING_TRUST_INCOMING=false` y llega un `traceparent` válido
- **THEN** la petición usa un trace id nuevo

### Requirement: Trace id en la respuesta
Cada respuesta SHALL incluir el trace id en el header `UBO_TRACING_RESPONSE_HEADER` (`X-Trace-Id` por
defecto). Con el valor vacío MUST no enviarse el header.

#### Scenario: Header por defecto
- **WHEN** se responde cualquier petición con la configuración por defecto
- **THEN** la respuesta trae `X-Trace-Id` con el trace id de la petición

#### Scenario: Respuesta de error
- **WHEN** una petición termina en una excepción que se responde como error
- **THEN** la respuesta de error también trae `X-Trace-Id`

#### Scenario: Header desactivado
- **WHEN** `UBO_TRACING_RESPONSE_HEADER` está vacío
- **THEN** la respuesta no trae el header del trace id

### Requirement: Ids de la traza en los logs
`trace_id`, `span_id` y `parent_span_id` SHALL agregarse al contexto de logs de la aplicación, de modo
que aparecen en cada línea de log de la unidad de trabajo.

#### Scenario: Log durante una petición
- **WHEN** el código de la aplicación escribe un log durante una petición
- **THEN** la línea incluye el `trace_id` y el `span_id` de esa petición

### Requirement: Propagación a llamadas salientes
El contrato `TraceContext` SHALL entregar el header `traceparent` con el trace id actual y el span actual
como padre. Las llamadas de Auth a API-Auth y las de ApiClient a otras APIs MUST enviarlo.

#### Scenario: Llamada a otra API
- **WHEN** durante una petición se llama a otra API con `UboApi::call`
- **THEN** la llamada lleva `traceparent` con el mismo trace id y el span de la petición como padre

### Requirement: Traza en jobs encolados
Un job encolado SHALL continuar la traza de quien lo despachó con un span propio, hijo del span que lo
despachó.

#### Scenario: Job despachado en una petición
- **WHEN** una petición despacha un job y el job escribe un log
- **THEN** el log tiene el mismo `trace_id`, un `span_id` nuevo y como `parent_span_id` el span de la petición

### Requirement: Traza fuera de una petición
Fuera de una petición HTTP (comando, tarea programada), la traza SHALL iniciarse en el primer uso del
contexto de traza.

#### Scenario: Comando que llama a una API
- **WHEN** un comando artisan llama a otra API con `UboApi::call`
- **THEN** la llamada lleva un `traceparent` de una traza nueva
