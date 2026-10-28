# Tasks

## 1. Fusión profunda de la configuración

- [x] 1.1 Crear en `src/Support/` un helper interno (trait para los service providers) que fusione la configuración del paquete con la de la aplicación en profundidad y no haga nada si la configuración está en caché, y verificarlo con tests de la función de fusión en `tests/Support/ConfigMergeTest.php`: una clave anidada que falta se toma del paquete, una clave presente conserva el valor de la aplicación (incluido `null`), una lista y el arreglo vacío se reemplazan completos, y funciona con tres niveles de anidamiento
- [x] 1.2 Usar el helper en `ModuleServiceProvider::register()` y `UboCommonServiceProvider::register()` en lugar de `mergeConfigFrom`, y verificar con `TestCase::$bootConfig` los escenarios de la spec: `ubo-auth.cache` publicado sin `service_token_margin` toma 60 y conserva las demás claves, `ubo-apis.defaults.timeout = 30` se respeta y `retries` sigue en 2, y `ubo-common.modules` publicado solo con `auth` deja `sqlserver` en `false`
- [x] 1.3 Verificar con un test que, si existe un archivo de configuración en caché con un `ubo-auth` parcial, las claves que le faltan no se completan (escenario "Configuración en caché"), y borrar el archivo al terminar el test
- [x] 1.4 Actualizar `docs/framework.md` (sección 3, regla 5) para decir que la configuración publicada recibe las claves anidadas nuevas del paquete, y verificar que el texto coincide con el requisito "Fusión profunda de la configuración publicada"

## 2. Valores por defecto en AuthSettings

- [x] 2.1 Agregar a `AuthSettings` constantes con los valores por defecto y los helpers `positiveInt`, `nonNegativeInt` y `filledString`, aplicados a `timeout`, `cache.public_key_ttl`, `cache.introspection_ttl`, `cache.service_token_margin`, `cache.prefix`, `strategy` y `leeway`, y verificarlo en `tests/Auth/AuthSettingsTest.php` (nuevo) con `''`, `null`, un texto no numérico, `'0'` y un negativo para cada campo numérico, `leeway = '0'` que queda en 0, valores válidos que se respetan y `fromConfig([])` que no lanza errores
- [x] 2.2 Verificar los escenarios de punta a punta: con `cache.public_key_ttl = 0` dos validaciones seguidas descargan la clave una sola vez, con `strategy = ''` se resuelve el validador híbrido y con `strategy = 'oauth'` resolver `TokenValidator` sigue lanzando `InvalidArgumentException`
- [x] 2.3 Documentar en `docs/modules/auth.md` que una variable definida vacía usa el valor por defecto, y verificar que la lista de valores coincide con el requisito "Valores de configuración vacíos usan el valor por defecto"

## 3. Endpoints fijos con override por variable

- [x] 3.1 Definir las tres rutas como constantes en `AuthSettings`, dejar de leer `endpoints` y leer en su lugar `endpoint_overrides`; reemplazar en `src/Auth/config/ubo-auth.php` el arreglo `endpoints` por `endpoint_overrides` con `UBO_AUTH_ENDPOINT_TOKEN`, `UBO_AUTH_ENDPOINT_INTROSPECT` y `UBO_AUTH_ENDPOINT_PUBLIC_KEY` sin valor por defecto; y verificar en `tests/Auth/` que un `endpoints.token = '/oauth/token'` publicado se ignora, que un override de introspección cambia la URL llamada y que un override vacío usa la ruta del paquete
- [x] 3.2 Hacer que `ApiClientManager::fake()` obtenga la URL de token con `AuthSettings::fromConfig()` en vez de leer `ubo-auth.endpoints.token`, sin depender de que el módulo Auth esté registrado, y verificar que los tests de `tests/ApiClient/FakeTest.php` siguen pasando y que, con `UBO_AUTH_ENDPOINT_TOKEN` reemplazado, el fake sigue interceptando la petición de token
- [x] 3.3 Documentar las variables `UBO_AUTH_ENDPOINT_*` en `docs/modules/auth.md` y agregar en `## [Unreleased]` de `CHANGELOG.md` una entrada `### Fixed` (valores vacíos y fusión profunda) y una `### Changed` (la clave `endpoints` publicada se ignora y se reemplaza por las variables), y verificar que siguen el formato de las entradas anteriores

## 4. Sin validación al registrar los módulos

- [x] 4.1 Verificar con un test en `tests/Support/` que la aplicación arranca, con el middleware `ubo.auth` registrado, aunque `ubo-auth` no tenga URL, credenciales ni ninguna otra clave (escenario "Build sin .env"), y que ningún código nuevo valida la configuración en `register()` ni en `boot()`

## 5. Verificación final

- [x] 5.1 Ejecutar la suite completa (`php vendor/bin/pest`) y `php vendor/bin/pint --test`, y verificar que todo pasa sin errores, incluidos los tests existentes que usan `TestCase::$bootConfig`
- [x] 5.2 Ejecutar `openspec validate configuracion-robusta --strict` y verificar que no reporta errores

## Workflow follow-up

- Probar API-Maestros con esta versión (`composer update ubo/common` y su suite en Docker) antes de publicar la 3.1.0.
- Archivar el cambio con `/opsx:archive` después del merge.
