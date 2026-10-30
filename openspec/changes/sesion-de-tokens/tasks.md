# Tasks

## 1. Auth: renovar con el refresh token

- [x] 1.1 Agregar `refresh()` a `Contracts\UserTokenClient` y a `HttpUserTokenClient` (`grant_type=refresh_token` en `/api/auth/oauth/token`, mismos errores que el canje), y hacer que `exchangeCode()` devuelva también `refresh_token` (nulo si IAM no lo entrega). Verificar en `tests/Auth/UserTokenClientTest.php` los escenarios de `auth-user-token`: canje con y sin refresh token, renovación correcta con su cuerpo, `invalid_grant`, mensaje sin secretos, 429 que no abre el circuito, circuito abierto sin enviar y falla de conexión

## 2. Bóveda de tokens

- [x] 2.1 Agregar a `LoginSettings` y `ubo-login.php` el almacén (`UBO_LOGIN_STORE`), el margen de renovación (`UBO_LOGIN_REFRESH_MARGIN`), la revalidación (`UBO_LOGIN_REVALIDATE`), el tope (`UBO_LOGIN_MAX_AGE`) y el TTL de la bóveda (`UBO_LOGIN_REFRESH_TTL`), con valores por defecto también en el código; verificar en `tests/Login/SettingsTest.php` los valores por defecto con variables vacías, ceros y valores no numéricos
- [x] 2.2 Crear la bóveda (`Infrastructure\TokenVault`: entrada cifrada con `Crypt`, identificador aleatorio, TTL, lectura y borrado) y hacer que `LoginSession` la use (la sesión solo guarda el identificador), que el callback guarde también el refresh token con un identificador nuevo y que `UboLogin::actingAs()` escriba en ella. Verificar en `tests/Login/VaultTest.php` los escenarios de `login-user`: la sesión no guarda tokens, el valor del almacén no los trae en claro, el identificador cambia en cada login y el anterior ya no da acceso, y la respuesta del callback no los trae; y que `CallbackTest`, `GuardTest`, `AuthorizationTest` y `ActingAsTest` siguen pasando con las lecturas adaptadas a la bóveda

## 3. Renovación

- [x] 3.1 Crear `Infrastructure\LoginTokens` (`current($handle)`) con la renovación bajo `Cache::lock`, la relectura tras el bloqueo, la validación del token renovado, la actualización de roles, scopes y audiencias, y el manejo de `invalid_grant`, roles vacíos, token de otra persona, IAM caído, circuito abierto y 429; y hacer que `UboGuard` lo use. Verificar en `tests/Login/RenewalTest.php` los escenarios de `login-user`: renovación antes de vencer con los tokens nuevos guardados, sin renovar antes del margen, roles actualizados, otra petición ya renovó, refresh rotado por otra petición, token de otra persona, `invalid_grant`, roles vacíos, sin refresh token, IAM caído con token vigente y vencido, y 429
- [x] 3.2 Verificar en `tests/Login/RenewalTest.php` que la renovación usa el almacén y el bloqueo de `UBO_LOGIN_STORE`, que una entrada borrada de la bóveda termina la sesión sin error y que, con un almacén sin bloqueos, la renovación igual funciona

## 4. Revalidación y tope

- [x] 4.1 Hacer que `LoginTokens` revalide cada `UBO_LOGIN_REVALIDATE` segundos con `TokenValidator` (solo con `introspection`/`hybrid` y credenciales M2M, tolerando que IAM no responda) y termine la sesión al alcanzar `UBO_LOGIN_MAX_AGE`. Verificar en `tests/Login/RevalidationTest.php` los escenarios de `login-user`: token revocado en otro sistema, sin comprobar antes de tiempo, estrategia `jwt` y sin credenciales M2M, IAM caído, tope alcanzado y sin tope

## 5. Logout

- [x] 5.1 Hacer que el logout revoque el token vigente de la bóveda (el ya renovado) y borre la entrada. Verificar en `tests/Login/LogoutTest.php` que la revocación lleva el token renovado y no el del login, que la bóveda queda sin la entrada y que los escenarios existentes siguen pasando

## 6. Documentación y verificación

- [x] 6.1 Actualizar `docs/modules/login.md` (quitar la limitación de la parte 1 y explicar la bóveda, la renovación, la revalidación, el tope, las variables nuevas y el almacén compartido), `README.md` y `CHANGELOG.md` (`### Added`, y cambiar la entrada de la parte 1 de "no se renueva"); verificar que cada variable y ejemplo coincide con `ubo-login.php` y con los tests
- [x] 6.2 Ejecutar `php vendor/bin/pest` y `php vendor/bin/pint --test`, y verificar que pasan sin errores
- [x] 6.3 Ejecutar `openspec validate sesion-de-tokens --strict` y verificar que no reporta errores

## Workflow follow-up

- Implementar en la rama `feat/sesion-de-tokens`, creada desde `main`.
- Repetir contra un IAM local el recorrido de la parte 1 y probar la renovación real: esperar o acortar `PASSPORT_ACCESS_TOKEN_TTL`, peticiones paralelas al vencer, logout en otro sistema y cambio de rol.
- Archivar el cambio con `/opsx:archive` después del merge, y publicar 3.2.0.
- Proponer aparte `->asUser()` en ApiClient, que usará la bóveda.
