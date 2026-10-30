# Tasks

## 1. Auth: tokens de una persona

- [x] 1.1 Crear el contrato `Auth\Contracts\UserTokenClient` (`exchangeCode(...)` y `revoke(...)`), la excepción `UserTokenRejected` y su implementación `Infrastructure\HttpUserTokenClient` sobre `ApiAuthClient` (canje en `/api/auth/oauth/token`, revocación en `/api/auth/v1/logout` con un 401 como éxito, errores como `UserTokenRejected` o `AuthServerUnavailable`); enlazarlos en `AuthServiceProvider`. Verificar con tests nuevos en `tests/Auth/UserTokenClientTest.php` (con `Http::fake`) los escenarios de `auth-user-token`: canje correcto con las credenciales indicadas, rechazo sin secretos en el mensaje, revocación y 401, circuito abierto sin enviar, fallas que abren el circuito compartido y cabecera `traceparent`
- [x] 1.2 Crear el contrato `Auth\Contracts\LocalTokenValidator` (extiende `TokenValidator`), hacer que `JwtValidator` lo implemente y enlazarlo en `AuthServiceProvider`; verificar en `tests/Auth/UserTokenClientTest.php` que se resuelve siempre al validador local, también con `UBO_AUTH_STRATEGY=introspection`, y que no hace llamadas de red

## 2. Esqueleto del módulo

- [x] 2.1 Crear `src/Login/LoginServiceProvider.php`, `LoginSettings` y `src/Login/config/ubo-login.php` (URL pública, client id y secreto del login, `redirect_uri`, `prompt`, prefijo de rutas, destino inicial, retorno del logout, vencimiento del `state`), con valores por defecto también en `LoginSettings`; registrar `'login' => LoginServiceProvider::class` en `UboCommonServiceProvider::MODULES`, `'login' => (bool) env('UBO_COMMON_LOGIN', false)` en `config/ubo-common.php` y declarar `illuminate/session` e `illuminate/routing` en `composer.json`. Verificar en `tests/Support/ModulesTest.php` y `tests/Support/PublishedConfigTest.php` los escenarios de `module-system` (por defecto apagado, activarlo registra rutas, guard y alias, y una config publicada sin la clave `login` lo deja apagado) y en `tests/Login/SettingsTest.php` los valores por defecto con variables vacías y que registrar el módulo sin variables no falla
- [x] 2.2 Agregar `tests/Login/` a `tests/Pest.php` (`->in(...)`, con el módulo activado y sesión `array`), y verificar que la suite existente sigue pasando

## 3. Inicio del login

- [x] 3.1 Implementar la clase `LoginSession` (datos pendientes del login y sesión autenticada en la sesión de Laravel) y `GET <prefijo>/login`: `state` y verificador aleatorios, `code_challenge` S256, URL pública frente a interna, `redirect_uri` desde `APP_URL`, destino original solo de la misma aplicación y `prompt=login` por configuración o `reauth=1`. Verificar en `tests/Login/LoginRedirectTest.php` los escenarios de `login-flow`: parámetros de la redirección, PKCE verificable contra la sesión, inicios distintos, URL pública e interna, cabecera `Host` manipulada, destino de otro sitio y `prompt`

## 4. Callback

- [x] 4.1 Implementar `GET <prefijo>/callback`: validar el `state` (de un solo uso, vencimiento, sin inicio previo), procesar `error`, canjear el código con `UserTokenClient` y las credenciales de login, validar el token con `LocalTokenValidator` (cliente de login, persona), rechazar sin roles con `NoAccess`, rechazar el controlador de sesión `cookie` y crear la sesión regenerando el identificador y el CSRF; crear las excepciones `LoginFailed` y `NoAccess` y el error de configuración incompleta. Verificar en `tests/Login/CallbackTest.php` los escenarios de `login-flow` y `login-user`: state incorrecto, ausente, vencido y repetido, `error=access_denied`, canje correcto y su cuerpo, `invalid_grant`, IAM caído, token con otra firma, de otro cliente y sin roles, login sin credenciales M2M, regeneración del identificador, redirección al destino, `SESSION_DRIVER=cookie` y secreto ausente

## 5. Usuario autenticado

- [x] 5.1 Implementar `UboUser` y el guard `ubo` (`Auth::extend`, declarado en `auth.guards.ubo` si falta, sin tocar el guard por defecto), con el fin de la sesión al vencer el token (menos `UBO_AUTH_LEEWAY`). Verificar en `tests/Login/GuardTest.php` los escenarios de `login-user`: guard sin configuración y guard propio, datos del usuario (`id`, `hasRole`, `hasScope`), sin sesión, token vigente y token vencido con `travel()`, y que ninguna cabecera ni cookie de la respuesta del callback contiene el token

## 6. Autorización

- [x] 6.1 Implementar `Gate::before` (solo `true` o `null`) y los alias `ubo.login` y `ubo.role`. Verificar en `tests/Login/AuthorizationTest.php` los escenarios de `login-authorization`: `@can` y `can:` con un scope, scope ausente, política de la aplicación que sigue funcionando, visitante, redirección web con retorno a la página pedida, 401 JSON, `ubo.role` con uno de varios roles, sin ninguno y con otra capitalización

## 7. Logout

- [x] 7.1 Implementar `POST <prefijo>/logout` (revocación con `UserTokenClient`, cierre local, redirección al logout de IAM con `client_id` y `return`, tolerante a fallas de la revocación y sin sesión) y `scope=local`. Verificar en `tests/Login/LogoutTest.php` los escenarios de `login-flow`: cierre completo, IAM caído al revocar, sin sesión, cierre local, que la ruta exige CSRF y que el `return` sale de la configuración y no de la petición

## 8. Pruebas de las aplicaciones

- [x] 8.1 Implementar `Login\Testing\UboLogin::actingAs()` (id, roles, scopes, audiencias; `LogicException` en producción). Verificar en `tests/Login/ActingAsTest.php` los escenarios de `login-user`: ruta con `ubo.login`, `@can` y `ubo.role` sin llamadas de red, y producción

## 9. Documentación

- [x] 9.1 Escribir `docs/modules/login.md` (qué es, variables, alta del cliente en IAM, `.env`, rutas, guard, `@can`, roles, logout, pruebas con `actingAs`, límites de esta parte, cookies en local y `SameSite`), agregar el módulo al catálogo de `docs/framework.md` y a `README.md`, la sección del contrato nuevo en `docs/modules/auth.md` y las entradas `### Added` de `CHANGELOG.md`; verificar que cada variable y ejemplo coincide con `ubo-login.php` y con los tests

## 10. Verificación final

- [x] 10.1 Ejecutar `php vendor/bin/pest` y `php vendor/bin/pint --test`, y verificar que pasan sin errores
- [x] 10.2 Ejecutar `openspec validate login-central --strict` y verificar que no reporta errores

## Workflow follow-up

- Implementar en la rama `feat/login-central`, creada desde `main`.
- Probar el recorrido completo (authorize, login en IAM, callback, `@can`, logout) contra un IAM local antes de publicar: crear la aplicación, un rol con scopes, un usuario y un cliente `authorization-code` con la `redirect_uri` del monolito de prueba, y revisar también el 429 de IAM y `POST /api/auth/v1/logout` con un token del cliente de código.
- Archivar el cambio con `/opsx:archive` después del merge.
- Proponer aparte la parte 2 (renovación con refresh token, bóveda, revalidación y tope de sesión) y `->asUser()` en ApiClient.
