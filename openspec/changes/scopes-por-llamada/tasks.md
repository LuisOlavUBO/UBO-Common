# Tasks

## 1. Scopes por llamada

- [ ] 1.1 Agregar a `PendingUboRequest` un método propio `scopes(array $scopes): static` (no a través de `__call`) que guarde los scopes de la llamada, y hacer que `prepare()` pida el token con `token(scopes: ..., api: ...)` y que la renovación ante un 401 use `forget()` y `token()` con esos mismos scopes; sin `->scopes()`, la lista queda vacía y el token se pide como hoy. Verificar en `tests/ApiClient/CallTest.php` los escenarios de la spec: sin `->scopes()` el token se pide con `api` y sin `scope` (el test existente sigue pasando), con `->scopes(['geografia:leer'])` se pide con `scope=geografia:leer`, y un `invalid_scope` de API-Auth lanza `UboApiException` sin llamar a la API
- [ ] 1.2 Verificar en `tests/ApiClient/CallTest.php` que la misma API llamada con `->scopes(['a'])` y luego sin scopes pide dos tokens y que cada llamada lleva el suyo, y que un 401 en una llamada con `->scopes(['geografia:leer'])` renueva solo ese token (el token sin scopes en caché se sigue usando en la llamada siguiente sin pedirse de nuevo)
- [ ] 1.3 Verificar en `tests/ApiClient/CallAsyncTest.php` que `callAsync(...)->scopes(['geografia:leer'])->get(...)` pide el token con `scope=geografia:leer`
- [ ] 1.4 Documentar `scopes()` en su docblock y en el ejemplo de la clase `PendingUboRequest` (al ser un método real, `UboClient` y `UboAsyncClient` lo heredan sin `@method`), actualizar la sección "Tokens por API" de `docs/modules/api-client.md` (el token por defecto trae todos los scopes del rol; `->scopes()` lo limita, y API-Auth rechaza los que el rol no tiene en esa API) y agregar la entrada `### Added` en `## [Unreleased]` de `CHANGELOG.md`, y verificar que los textos coinciden con el requisito "Token por API obtenido automáticamente"

## 2. Verificación final

- [ ] 2.1 Ejecutar la suite completa (`php vendor/bin/pest`) y `php vendor/bin/pint --test`, y verificar que todo pasa sin errores
- [ ] 2.2 Ejecutar `openspec validate scopes-por-llamada --strict` y verificar que no reporta errores

## Workflow follow-up

- Implementar en una rama nueva desde `main`.
- Confirmar que la imagen de API-Auth desplegada sea `v1.0.0` o posterior antes de que un sistema use `->scopes()` en producción.
- Archivar el cambio con `/opsx:archive` después del merge.
