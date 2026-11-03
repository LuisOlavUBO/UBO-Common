# Design

## Context

Ver proposal.md (Why). API-Auth `59a142a`: la claim `uuid` va solo en tokens de usuario y la introspección devuelve
`uuid` (`null` en tokens de cliente). SQL Server guarda el `uuid` en mayúsculas, pero API-Auth lo expone en
minúsculas. El login guarda a la persona en la bóveda (`id` = `sub`), y el guard arma el `UboUser` desde esa entrada.

## Goals / Non-Goals

**Goals:** que el identificador que Laravel usa por defecto (`Auth::id()`) sea el `uuid`, y que el `uuid` esté
disponible también en las APIs.

**Non-Goals:** cambiar `VerifiedToken::$userId`; buscar personas por `uuid`; el RUT (API-Auth no lo pone en el token).

## Decisions

- **`VerifiedToken::$uuid` es un parámetro nuevo al final del constructor (`?string $uuid = null`).** Así no rompe a
  quien construye `VerifiedToken` a mano. Se normaliza a minúsculas para que el mismo `uuid` compare igual venga de
  donde venga. Solo se toma en tokens de usuario: si un token de sistema lo trajera, se ignora.
- **El `UboUser` cambia de significado, no de forma.** `id` (y `getAuthIdentifier()`) pasa a ser el `uuid`, y se
  agrega `sub`. Alternativa descartada: dejar `id` = `sub` y agregar `uuid` aparte, porque deja en manos de cada
  aplicación usar el identificador correcto, y la regla de API-Auth es justo la contraria.
- **La bóveda guarda `uuid` aparte de `id` (= `sub`).** La renovación sigue comparando `sub` con `id`, y además
  compara `uuid` cuando la sesión ya lo tiene.
- **Una sesión sin `uuid` se renueva de inmediato** (`dueForRenewal` es verdadero si falta). `store()` guarda el `uuid`
  del token renovado, y si este no lo trae, termina la sesión. Sin refresh token, `current()` termina la sesión. El
  guard nunca arma un `UboUser` sin `uuid`. Alternativa descartada: usar `sub` mientras tanto, porque mezclaría los
  dos tipos de identificador en los datos de los satélites.
- **El login rechaza un token sin `uuid`** (`LoginFailed::invalidToken('it has no "uuid" claim: update API-Auth')`):
  así se detecta de inmediato un despliegue en el orden equivocado.
- **`TokenFactory::uuidFor($userId)`** genera un uuid con formato válido, a partir de `md5('ubo-test:'.$userId)`,
  determinístico para que los tests puedan comparar sin guardar el valor. `forUser()` lo usa si no se pasa `uuid:`.
- **`UboLogin::actingAs(id: …)`**: `id` es el `uuid` que verá `Auth::id()`, y `sub` (opcional) es por defecto el
  mismo `id`. Los tests de las aplicaciones que usan `actingAs(id: '42')` y esperan `Auth::id() === '42'` siguen
  pasando.

## Risks / Trade-offs

- **[Un monolito con el módulo Login en uso que haya guardado `Auth::id()` (= `sub`)]** → Se asumió que no existe (el
  módulo salió en la 3.2.0). Se avisa en el CHANGELOG; si existiera, tendría que migrar esos datos.
- **[Common se despliega antes que la `uuid` de API-Auth]** → Ningún login prospera y las sesiones existentes
  terminan al renovarse. Se documenta el orden, y el error del login lo dice explícitamente.
- **[Al desplegar, cada sesión abierta renueva una vez]** → Es un pico de llamadas a `/oauth/token`, acotado al número
  de sesiones activas; un 429 no termina la sesión, y se reintenta en la siguiente petición.

## Migration Plan

1. Desplegar API-Auth con la claim `uuid`.
2. Publicar Common 3.3.0 y actualizar los monolitos. Las sesiones abiertas se renuevan solas.
3. Rollback: volver a 3.2.x. `Auth::id()` vuelve a ser `sub`; la bóveda ignora el campo `uuid`.
