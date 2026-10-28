# Tasks

## 1. Reconocer la clave de prueba

- [x] 1.1 Crear `src/Auth/Testing/TestKey.php` (`@internal`) que calcule la huella normalizada de un PEM (SHA-256 del contenido sin espacios, saltos de línea, `\n` literales ni líneas `-----BEGIN/END-----`) y diga si es la clave pública de prueba, y verificarlo en `tests/Auth/TestKeyTest.php`: reconoce `test-public.key` tal cual, con CRLF, con `\n` literales y con espacios al final, y no reconoce otra clave pública válida

## 2. Validación en producción

- [x] 2.1 Hacer que `JwtValidator`, con `app()->isProduction()`, revise la clave obtenida de `PublicKeyProvider` (la de la caché y la refrescada tras una rotación) antes de verificar la firma y, si es la de prueba, registre un `critical` en el log y lance `AuthServerUnavailable`; guardar el resultado por clave y proceso para no recalcular la huella en cada petición
- [x] 2.2 Verificar en `tests/Auth/TestKeyProductionTest.php` (con `$this->app['env'] = 'production'`) los escenarios de la spec: clave de prueba fijada en `UBO_AUTH_PUBLIC_KEY` y token firmado con la clave privada de prueba en una ruta con `ubo.auth` (503 y el controlador no se ejecuta), clave de prueba descargada de API-Auth (ningún token se acepta y el log tiene el motivo, con `Log::spy()`), clave de prueba con CRLF o espacios al final (se rechaza), y la estrategia `hybrid` (no llama a la introspección)
- [x] 2.3 Verificar que fuera de producción no cambia nada: con `APP_ENV=testing` y `local` los tokens firmados con la clave de prueba se aceptan, y la suite existente de `tests/Auth/` sigue pasando sin cambios

## 3. TokenFactory y comando de diagnóstico

- [x] 3.1 Hacer que `TokenFactory::fake()` lance `LogicException` en producción antes de cambiar la configuración, y verificar con un test que la excepción se lanza y que `ubo-auth.public_key` no cambia
- [x] 3.2 Agregar a la revisión "Public key" de `CheckCommand` el chequeo de que, en producción, la clave no sea la de prueba, y verificar en `tests/Auth/CheckCommandTest.php` que en producción con la clave de prueba muestra FAIL indicando que es la clave de prueba y termina con error, y que los escenarios existentes siguen pasando
- [x] 3.3 Actualizar `docs/modules/auth.md` (sección de tests: la clave de prueba se rechaza en producción y `fake()` falla ahí), `src/Auth/Testing/keys/README.md` y agregar la entrada en `## [Unreleased]` de `CHANGELOG.md` (`### Security`), y verificar que los textos coinciden con los requisitos modificados

## 4. Verificación final

- [x] 4.1 Ejecutar la suite completa (`php vendor/bin/pest`) y `php vendor/bin/pint --test`, y verificar que todo pasa sin errores
- [x] 4.2 Ejecutar `openspec validate bloquear-clave-de-prueba --strict` y verificar que no reporta errores

## Workflow follow-up

- Implementar en una rama nueva desde `main`, independiente de `fix/configuracion-robusta`.
- Archivar el cambio con `/opsx:archive` después del merge.
