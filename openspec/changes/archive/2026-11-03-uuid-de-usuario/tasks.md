# Tasks

## 1. Auth

- [x] 1.1 Agregar `?string $uuid = null` a `VerifiedToken`, leído de la claim `uuid` en `JwtValidator` y del campo `uuid` en `IntrospectionValidator` (minúsculas; solo en tokens de usuario); verificar en `tests/Auth/VerifiedTokenTest.php`, `MiddlewareTest.php` e `IntrospectionTest.php` los escenarios de "Identificador público de la persona"
- [x] 1.2 Agregar la claim `uuid` y el parámetro `uuid:` a `TokenFactory::forUser()`, con `TokenFactory::uuidFor()`; verificar el escenario "uuid del token de usuario"

## 2. Login

- [x] 2.1 Exigir `uuid` en el token del login (`LoginController::verify`) y guardarlo en la bóveda; verificar en `tests/Login/CallbackTest.php` "Token sin uuid" y que la entrada guarda el `uuid`
- [x] 2.2 `UboUser`: `id` = `uuid` y `sub` nuevo; el guard lo arma desde la bóveda; verificar en `tests/Login/GuardTest.php` "Datos del token" (`Auth::id()` es el `uuid`)
- [x] 2.3 `LoginTokens`: renovar de inmediato una sesión sin `uuid`, guardar el `uuid` renovado, terminar si falta o cambia, y terminar una sesión sin `uuid` ni refresh token; verificar en `tests/Login/RenewalTest.php` los escenarios de "Sesiones sin uuid"
- [x] 2.4 `UboLogin::actingAs()`: `id` como `uuid` y `sub` opcional; verificar en `tests/Login/ActingAsTest.php`

## 3. Documentación y cierre

- [x] 3.1 Documentar en `docs/modules/auth.md` (`uuid` en `VerifiedToken` y la regla de guardar el `uuid`), `docs/modules/login.md` (`Auth::id()` = `uuid`, `$user->sub`, sesiones antiguas, orden de despliegue) y `docs/sso.md` (claim `uuid` en la tabla 3.3); agregar las entradas en `## [Unreleased]` del CHANGELOG con el aviso del cambio de `Auth::id()`
- [x] 3.2 Ejecutar la suite (`php vendor/bin/pest`), `composer lint` y `openspec validate uuid-de-usuario --strict`, y verificar que pasan
