# Proposal

## Why

`UboApi::call()` pide el token sin scopes, así que el token trae todos los scopes que los roles del sistema
tienen en la API destino. Una llamada que solo lee recibe también los permisos de escritura, y si el token
se filtra sirve para más de lo necesario (hallazgo 5 del diagnóstico de arquitectura, mínimo privilegio).

## What Changes

- Se agrega `->scopes([...])` al cliente de `UboApi::call()` y `UboApi::callAsync()`: la llamada usa un
  token emitido solo con esos scopes para esa API.
- Sin `->scopes()`, el comportamiento no cambia: el token trae todos los scopes del rol en esa API.
- Cada combinación de API y scopes tiene su propio token en caché, y la renovación ante un 401 renueva el
  token de esa misma combinación.
- Si API-Auth no permite alguno de los scopes pedidos, la llamada falla con `UboApiException` antes de
  llegar a la API, como hoy cuando no se obtiene el token.
- No hay cambios **BREAKING**: es una opción nueva.

## Capabilities

### New Capabilities

Ninguna.

### Modified Capabilities

- `api-client`: el requisito "Token por API obtenido automáticamente" admite scopes explícitos por llamada.

## Impact

- Código: `PendingUboRequest` (método `scopes()`, obtención y renovación del token). `ServiceTokenProvider`
  ya acepta scopes y API, así que no cambia ninguna interfaz.
- Dependencia de API-Auth: debe aceptar `scope` junto con `api` en client credentials. Confirmado: lo hace
  desde el commit `827312a` (tag `v1.0.0`), en `ScopeRepository::scopesForApi()`. Con `scope`, el token
  lleva solo los scopes pedidos si todos están en los roles del cliente para esa API; si no, responde
  `invalid_scope`. Lo cubre el test `test_token_for_an_api_can_be_narrowed_to_some_of_its_scopes`. Falta
  confirmar que la imagen desplegada de API-Auth sea `v1.0.0` o posterior.
- Tests nuevos en `tests/ApiClient/` (síncrono y asíncrono).
- Documentación: sección "Tokens por API" de `docs/modules/api-client.md`.
- Versión: 3.1.0 (minor).
