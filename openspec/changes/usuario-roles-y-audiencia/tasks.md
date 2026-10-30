# Tasks

## 1. Roles en el token verificado

- [x] 1.1 Agregar a `VerifiedToken` el parámetro `roles` (lista de textos, último y con `[]` por defecto) y el método `hasRole(string $role)`; verificar en `tests/Auth/VerifiedTokenTest.php` que `hasRole()` distingue mayúsculas y minúsculas y que un `new VerifiedToken(...)` con los argumentos anteriores sigue funcionando con `roles` vacío
- [x] 1.2 Hacer que `JwtValidator` lea la claim `roles` (solo textos; ausente o no arreglo ⇒ lista vacía, sin rechazar) y verificar en `tests/Auth/MiddlewareTest.php` los escenarios de la spec: token con roles, sin la claim y con la claim como texto
- [x] 1.3 Hacer que `IntrospectionValidator` guarde y lea `roles` en la respuesta y en su caché (entrada sin `roles` ⇒ lista vacía), y verificar en `tests/Auth/IntrospectionTest.php` los escenarios de la spec: respuesta con roles, sin roles, y resultado en caché sin `roles` que no hace otra llamada
- [x] 1.4 Verificar en `tests/Auth/IntrospectionTest.php` (o `MiddlewareTest.php`) que con la estrategia `hybrid` los `roles` del token verificado salen de la claim del JWT

## 2. TokenFactory con roles y audiencias

- [x] 2.1 Agregar `$roles` a `forClient()` y `forUser()`, y `$audiences` a `forUser()`, ambos como últimos parámetros opcionales; el JWT lleva la claim `roles` y, con `$audiences`, el `aud` es el client id seguido de esas aplicaciones sin agregar `$api`; verificar en `tests/Auth/MiddlewareTest.php` los escenarios de `auth-testing` (M2M con roles, usuario con roles, usuario con audiencias de varias aplicaciones) y que los tests existentes de `forClient()` y `forUser()` siguen pasando sin cambios
- [x] 2.2 Documentar los parámetros nuevos en los docblocks de `TokenFactory`, actualizar la tabla de `docs/modules/auth.md` (firmas de `forClient` y `forUser`) y la sección "Después del middleware" con `roles` y `hasRole()`, y verificar que el ejemplo del documento coincide con las firmas reales

## 3. Verificación final

- [x] 3.1 Agregar la entrada `### Added` en `## [Unreleased]` de `CHANGELOG.md` (roles en `VerifiedToken`, lectura en JWT e introspección, parámetros de `TokenFactory`) y verificar que no declara cambios incompatibles
- [x] 3.2 Ejecutar `php vendor/bin/pest` y `php vendor/bin/pint --test`, y verificar que pasan sin errores (la suite parte de 328 tests en verde)
- [x] 3.3 Ejecutar `openspec validate usuario-roles-y-audiencia --strict` y verificar que no reporta errores

## Workflow follow-up

- Implementar en la rama `feat/usuario-roles-y-audiencia`, creada desde `main` en 3.1.0.
- Archivar el cambio con `/opsx:archive` después del merge.
- El cambio de revisión de audiencia en tokens de usuario y el módulo de login se proponen por separado.
