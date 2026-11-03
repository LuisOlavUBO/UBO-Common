# Tasks

## 1. Audiencia de los tokens de usuario (hallazgo 7)

- [x] 1.1 Quitar la condición `isMachineToken()` de la revisión de `aud` en `src/Auth/Http/Middleware/Authenticate.php` y reemplazar el test `user tokens are not checked against the API name` de `tests/Auth/MiddlewareTest.php` por los escenarios "Token de usuario de otra API" (401 `wrong_audience`) y "Token de usuario para esta API" (aceptado); verificar que los demás tests de `tests/Auth` siguen pasando
- [x] 1.2 Actualizar `docs/modules/auth.md` (sección "Tokens emitidos para otra API": los tokens de usuario también se revisan, y cómo hace IAM para que una API aparezca en el `aud` de un token de usuario) y verificar que coincide con el requisito "Audiencia de los tokens"

## 2. Token de la persona en el módulo Login

- [x] 2.1 Agregar a `LoginTokens` `renewedAfter(string $handle, string $rejected): ?array` según la decisión 3 de `design.md`, reutilizando `renew()` con un indicador para no mirar el margen; verificar en `tests/Login/RenewalTest.php` "Otra petición ya renovó" (sin llamar a IAM), "Renovación forzada" (IAM recibe el refresh token aunque falte para el margen), "Sin refresh token" (null sin llamar a IAM), un `invalid_grant` que termina la sesión e IAM caído (null, la entrada sigue)
- [x] 2.2 Crear el contrato `src/Login/Contracts/UserTokenProvider.php` y su implementación `src/Login/Infrastructure/SessionUserTokenProvider.php` (decisión 2: la persona por el guard `ubo`, el token desde la bóveda), registrada en `LoginServiceProvider`; verificar en un nuevo `tests/Login/UserTokenProviderTest.php` "Persona con sesión", "Token por renovar", "Sin sesión" (`AuthenticationException`), sesión terminada y que, con IAM sin responder, pedir el token después del guard no repite la llamada a IAM en la misma petición

## 3. `->asUser()` en ApiClient

- [x] 3.1 Agregar `asUser()` a `PendingUboRequest` con el proveedor perezoso desde `ApiClientManager` (decisión 4): orden persona → circuito → `prepare()`, token de la persona en lugar de `ServiceTokenProvider` y `LogicException` con el módulo `login` apagado; verificar en un nuevo `tests/ApiClient/AsUserTest.php` "Llamada como la persona" (sin petición a `/oauth/token`), "Sin asUser", "Ruta sin login" y "Desde un job" (`AuthenticationException`, nada enviado) y "Módulo login apagado"
- [x] 3.2 Renovar el token de la persona ante un 401 en el callback `when` con `renewedAfter()`; verificar en `tests/ApiClient/AsUserTest.php` "Otra petición renovó el token" (401 y luego 200 con el token nuevo) y "Token que no se puede renovar" (`UboApiException` 401 con un solo envío), y que el circuito, el tiempo total y la traza siguen aplicando con `->asUser()`
- [x] 3.3 Verificar en `tests/ApiClient/AsUserTest.php` "Llamada asíncrona como la persona" y "Persona simulada y API simulada" (`UboLogin::actingAs()` + `UboApi::fake()`, `Http::assertSent()` ve `test-access-token`), y agregar `asUser` al docblock de la facade `UboApi` si corresponde

## 4. Documentación y cierre

- [x] 4.1 Documentar `->asUser()` en `docs/modules/api-client.md` (uso, 401, errores, pruebas, cuándo usarlo y la configuración de la API destino y de IAM), en `docs/modules/login.md` (sección 5: el token de la persona para otras APIs; sección 7: pruebas con `asUser`) y en `docs/sso.md` (llamar a otras APIs como la persona); verificar que los ejemplos coinciden con los tests
- [x] 4.2 Agregar en `## [Unreleased]` del `CHANGELOG.md` las entradas `### Added` (`->asUser()`, `UserTokenProvider`) y `### Changed` (audiencia de los tokens de usuario, con el remedio), y verificar que siguen el formato de las anteriores
- [x] 4.3 Ejecutar la suite completa (`php vendor/bin/pest`) y `composer lint`, y verificar que todo pasa
- [x] 4.4 Ejecutar `openspec validate llamar-como-usuario --strict` y verificar que no reporta errores

## Workflow follow-up

- Probar contra un IAM local: login en un monolito, `->asUser()` hacia api-maestros con `UBO_AUTH_ALLOW_USER_TOKENS=true`, un rol sin el scope (403) y un token renovado en paralelo (401 y reintento).
- Archivar el cambio con `/opsx:archive` dentro del PR, y publicar 3.3.0 después del merge.
