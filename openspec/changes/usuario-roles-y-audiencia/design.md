# Design

## Context

La motivación está en el proposal (ver "Why") y los comportamientos exigidos, en los specs delta de
`auth-token-validation` y `auth-testing`.

**Cómo está hoy el código:**

- `VerifiedToken` (`src/Auth/Domain/`) es un `final readonly class` con `id, clientId, userId, scopes,
  expiresAt, audiences`. `audiences` ya se agregó como último parámetro con valor por defecto, y su
  constructor es público: una aplicación puede construirlo, por ejemplo en un validador falso.
- `JwtValidator::toVerifiedToken()` lee `jti`, `aud`, `sub` y `scopes`, e ignora `roles`, aunque API-Auth lo
  emite en todos sus tokens.
- `IntrospectionValidator::introspect()` guarda en caché un arreglo con `active, client_id, sub, scopes, aud,
  exp` y descarta `roles`; `validate()` lo convierte en `VerifiedToken`. `HybridValidator` devuelve el token del
  validador local, así que con `hybrid` los roles ya salen del JWT.
- `TokenFactory::issue()` emite el JWT con `permittedFor(clientId, api)` y la claim `scopes`; `forUser()` y
  `forClient()` comparten ese método. `FakeIntrospectionValidator` delega en el validador local.

**Restricciones:**

- Compatibilidad: ningún cambio de firma puede romper a quien ya llama a `new VerifiedToken(...)`,
  `forClient()` o `forUser()` con los argumentos actuales.
- Los resultados de introspección ya guardados en la caché compartida no tienen `roles`: se leen durante
  hasta `UBO_AUTH_INTROSPECTION_TTL` segundos después de desplegar.

## Goals / Non-Goals

**Goals:**

- Que el token verificado exponga los roles sin importar la estrategia de validación.
- Que los tests de las aplicaciones puedan emitir roles y las audiencias de un token de usuario real.

**Non-Goals:**

- Revisar roles en el middleware `ubo.auth`: los roles son datos para la aplicación, la ruta sigue
  autorizándose por scopes.
- Revisar la audiencia de los tokens de usuario: es otro cambio, cuando una API empiece a aceptarlos.
- Exponer en el token verificado el perfil (`user`) que devuelve la introspección.

## Decisions

- **`roles` como último parámetro opcional de `VerifiedToken`, con `hasRole()`.** Es la misma forma con la que
  se agregó `audiences`, y no rompe a quien construye el objeto. Alternativa descartada: un parámetro
  obligatorio, que sería un cambio MAJOR.
- **Un `roles` ausente o inválido es una lista vacía y no un token malformado.** Los tokens anteriores al
  cambio de roles de API-Auth no la traen y deben seguir validándose. A diferencia de `scopes`, `roles` no
  decide el acceso a una ruta de `ubo.auth`, así que no hay motivo para rechazar. Alternativa descartada:
  exigirla en `Claims obligatorios del token`, que habría invalidado esos tokens.
- **Solo se conservan los roles que son texto, en el mismo orden**, igual que ya se hace con `scopes` y `aud`.
  La capitalización se respeta: los nombres de rol son únicos por aplicación y se comparan tal cual.
- **La caché de introspección tolera entradas sin `roles`** leyendo `$claims['roles'] ?? []`, en vez de
  cambiar la clave de caché o vaciarla. Con la clave por hash de token, una clave nueva obligaría a todos los
  tokens a llamar de nuevo a API-Auth justo al desplegar. Las entradas nuevas llevan `roles`, y las viejas
  caducan solas en 30 s.
- **`TokenFactory`: `$roles` en `forClient()` y `forUser()`, y `$audiences` solo en `forUser()`**, como
  parámetros finales con valor por defecto. `$audiences = null` conserva el comportamiento actual (client id y
  API bajo prueba); con una lista, el `aud` es el client id y esas aplicaciones, que es lo que emite API-Auth
  en un token de usuario. `$audiences` no se agrega a `forClient()` porque un token M2M se dirige con `$api`.
  Alternativa descartada: un objeto de opciones, desproporcionado para dos valores opcionales.

## Risks / Trade-offs

- [Dos formas de armar `aud` en `forUser()`: por `$api` o por `$audiences`] → documentar en el docblock que
  `$audiences`, si se pasa, reemplaza a `$api`.
- [Durante hasta 30 s tras desplegar, un token en caché con `introspection` muestra `roles` vacío] →
  aceptado: los roles son informativos para quien aún no los usa, y quien los use (el módulo de login) se
  publica después.
- [Un nombre de rol igual en dos aplicaciones] → el token de un usuario solo lleva los roles de la
  aplicación de su cliente, así que no hay ambigüedad dentro de un token.
