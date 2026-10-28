# Design

## Context

- `ModuleServiceProvider::register()` y `UboCommonServiceProvider::register()` usan `mergeConfigFrom`, que
  hace `array_merge` de un solo nivel.
- `AuthSettings::fromConfig()` lee `strategy`, `leeway`, `timeout`, `cache.*` y `endpoints` sin valores por
  defecto y los convierte con `(int)` o `(string)`, así que `''` o `null` se vuelven `0` o `''`.
- `env('X', 5)` solo usa el `5` cuando la variable no existe; una variable vacía devuelve `''`.
- `ApiClientManager::fake()` arma la URL de token leyendo `ubo-auth.endpoints.token`.

## Goals / Non-Goals

**Goals:**
- Que ningún valor vacío deje a Auth sin timeout o sin caché.
- Que un archivo publicado viejo reciba las claves anidadas nuevas.
- Que las rutas de API-Auth no queden congeladas en un archivo publicado.

**Non-Goals:**
- Validar la configuración al arrancar (rompería `composer install` y los builds sin `.env`).
- Cambiar la configuración de los otros módulos, que ya tienen valores por defecto en código.

## Decisions

### Fusión profunda propia, en un solo lugar

`ModuleServiceProvider` y `UboCommonServiceProvider` usan un helper interno de `Support/` que fusiona en
profundidad y respeta `config:cache` (no hace nada si la configuración está en caché, como `mergeConfigFrom`).

Reglas: en arreglos asociativos se fusiona clave por clave; **una lista (`array_is_list`) se reemplaza
completa**, para que una lista publicada más corta no herede elementos del paquete por índice.

**Alternativa considerada:** `replaceConfigRecursivelyFrom` de Laravel. Usa `array_replace_recursive`, que
mezcla las listas por índice, y no está claro desde qué versión 12.x existe; obligaría a subir el mínimo
de `illuminate/support`.

### Valores por defecto en `AuthSettings`

Cada campo pasa por un helper privado: `positiveInt($value, $default)` para `timeout` y los TTL (vacío, no
numérico o `<= 0` → valor por defecto), `nonNegativeInt` para `leeway` y `filledString` para `strategy` y
`cache.prefix`. Los valores por defecto quedan como constantes de `AuthSettings`, iguales a los de
`config/ubo-auth.php`, que se mantienen para la documentación del archivo publicado.

### Endpoints: constantes del paquete + overrides por variable

- `AuthSettings` define las tres rutas como constantes y deja de leer `endpoints`.
- `config/ubo-auth.php` reemplaza `endpoints` por `endpoint_overrides`, con
  `env('UBO_AUTH_ENDPOINT_TOKEN')`, etc., sin valor por defecto. Un override vacío o nulo usa la constante.
- Un archivo publicado viejo trae `endpoints` (se ignora) y no trae `endpoint_overrides`, que la fusión
  completa desde el paquete: las variables siguen funcionando.
- `ApiClientManager::fake()` obtiene la URL de token de `AuthSettings::endpoint('token')`, para no duplicar la ruta.

**Alternativa considerada:** dejar `endpoints` en el archivo y documentar que no se publique. No resuelve
el problema para quien ya lo publicó.

## Risks / Trade-offs

- [Una app personalizó `endpoints` a propósito] → Deja de tener efecto; se documenta en el CHANGELOG con
  las variables de reemplazo. Ninguna app conocida lo tiene publicado.
- [Quitar `endpoints` del archivo del paquete contradice la regla de `framework.md` sobre claves públicas]
  → Se acepta como corrección en 3.1 por decisión explícita; la clave publicada se ignora sin error.
- [La fusión profunda cambia qué valores ve una app con configuración publicada] → Solo agrega claves que
  faltaban; los valores presentes en la app siguen ganando.
