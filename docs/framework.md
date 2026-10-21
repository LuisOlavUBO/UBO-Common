# Marco de trabajo de UBO.Common

Reglas para mantener y hacer crecer la librería. El objetivo es que cualquier desarrollador pueda
agregar una funcionalidad común sin romper a las aplicaciones que ya la usan.

## 1. Qué entra en UBO.Common

Entra lo que **dos o más aplicaciones** necesitan y que **no depende de las reglas de negocio de
ninguna de ellas**.

| Sí | No |
|---|---|
| Validar tokens de API-Auth | Decidir si un sistema puede consumir Maestros2 (tabla `sistema`) |
| Llamar a APIs de la UBO | Endpoints, controladores o rutas de una API |
| Formato de errores RFC 9457, caché HTTP, ajustes de SQL Server | Modelos o migraciones de una aplicación |

Si algo solo lo usa una aplicación, se queda en esa aplicación. Cuando aparezca un segundo uso, se
mueve a Common.

## 2. Estructura

```
src/
├── UboCommonServiceProvider.php    punto de entrada; carga los módulos activos (MODULES)
├── Support/                        base compartida por todos los módulos (no es un módulo)
└── <Module>/                       un módulo = una funcionalidad
    ├── <Module>ServiceProvider.php hereda de Support\ModuleServiceProvider
    ├── config/ubo-<module>.php     su configuración, con valores por defecto comentados
    ├── Contracts/                  interfaces: lo único que otros módulos pueden usar
    ├── Domain/                     objetos de valor y reglas propias del módulo
    ├── Infrastructure/             implementaciones (HTTP, caché, librerías externas)
    ├── Http/                       middleware (si aplica)
    ├── Console/                    comandos artisan (si aplica)
    ├── Exceptions/
    └── Testing/                    ayudas para los tests de las aplicaciones (fakes, fábricas)
tests/<Module>/                     tests del módulo
docs/modules/<module>.md            documentación del módulo
```

No todos los módulos necesitan todas las carpetas: se crean solo las que se usan.

## 3. Reglas de diseño

1. **Todo en inglés**: clases, métodos, configuración y mensajes de excepción. Las aplicaciones
   pueden estar en español.
2. **Los módulos no se conocen entre sí.** Si un módulo necesita algo de otro, depende de su
   interfaz en `Contracts/`, nunca de una clase de `Infrastructure/`.
   Ejemplo: `ApiClient` usa `Auth\Contracts\ServiceTokenProvider`.
3. **Las aplicaciones dependen de contratos**, y el `ServiceProvider` decide qué implementación
   entregar según la configuración. Ejemplo: `UBO_AUTH_STRATEGY` elige el `TokenValidator`.
4. **Sin formato propio de errores.** Se lanzan excepciones estándar de Laravel o del módulo, y cada
   aplicación las presenta a su manera. Ejemplo: `InvalidToken` hereda de `AuthenticationException`.
5. **Toda la configuración tiene un valor por defecto razonable** y se puede cambiar por `.env`.
6. **Cada módulo trae su ayuda de testing**, para que las aplicaciones prueben sin servicios externos.
   Ejemplos: `TokenFactory::fake()` y `UboApi::fake()`.
7. **Bindings no singleton** cuando dependen de la configuración, para que un cambio de configuración
   (por ejemplo en tests) se aplique de inmediato.
8. **Fallar cerrado en seguridad.** Si no se puede verificar algo, se rechaza (ej. API-Auth caído → 503).

## 4. Receta: agregar un módulo

1. Crear `src/<Module>/<Module>ServiceProvider.php`:

   ```php
   final class CacheServiceProvider extends ModuleServiceProvider
   {
       protected function configName(): string { return 'ubo-cache'; }
       protected function configPath(): string { return __DIR__.'/config/ubo-cache.php'; }

       protected function registerModule(): void { /* bindings */ }
       protected function bootModule(): void { /* middleware, comandos */ }
   }
   ```

2. Crear `src/<Module>/config/ubo-<module>.php` con valores por defecto comentados.
3. Registrarlo en `UboCommonServiceProvider::MODULES` y agregar su interruptor en
   `config/ubo-common.php` (`UBO_COMMON_<MODULE>`).
4. Escribir sus tests en `tests/<Module>/` y agregar la carpeta como suite en `phpunit.xml`.
5. Documentarlo en `docs/modules/<module>.md` y agregarlo a la tabla del `README.md`.
6. Anotar el cambio en `CHANGELOG.md` y publicar una versión **minor**.

## 5. Versionado y publicación

- Se usa versionado semántico con tags de git (`v1.0.0`, `v1.1.0`...).
- **Cambiar el comportamiento por defecto, renombrar o eliminar algo público es un cambio MAJOR.**
  Lo público son las clases fuera de `Infrastructure/`, la configuración, el middleware y los comandos.
- Cada aplicación fija su versión (`"ubo/common": "^1.2"`) y actualiza cuando quiere con
  `composer update ubo/common`.

## 6. Candidatos a próximos módulos

Duplicaciones detectadas al revisar API-Auth y API-Maestros2:

| Módulo | Qué contendría | Hoy está en |
|---|---|---|
| `SqlServer` | `SET DATEFORMAT ymd` al abrir conexiones `sqlsrv` | API-Auth y Maestros2 (`AppServiceProvider`) |
| `Problems` | Respuestas de error RFC 9457 con código estable en `error` | Maestros2 (`RenderizadorProblemas`) |
| `Responses` | Formato `{data, meta}` | Maestros2 (`ApiResource`, `ApiCollection`) |
| `HttpCache` | ETag y caché versionada por dominio | Maestros2 (`CacheHttp`, `VersionCacheDominio`) |
| `Health` | Endpoint de salud y verificadores | Maestros2 (`SaludController`) |
