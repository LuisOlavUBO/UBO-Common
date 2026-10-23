# Módulo Auth

Permite que una API **confíe en los tokens que emite API-Auth** sin tener su propio servidor OAuth.
API-Auth es el único que emite tokens; cada API solo los **valida**.

```
Sistema ──POST /oauth/token──► API-Auth (firma el JWT con su clave privada)
Sistema ──GET + Bearer──────► API  ──► middleware ubo.auth ──► reglas de la API ──► controlador
                                        (valida con la clave pública de API-Auth)
```

## 1. Configuración en la API

```dotenv
UBO_AUTH_URL=https://api-auth.ubo.cl
UBO_AUTH_API=api-maestros        # nombre de esta API en API-Auth (oauth:api)
UBO_AUTH_STRATEGY=hybrid
UBO_AUTH_CLIENT_ID=<credencial de esta API en API-Auth>
UBO_AUTH_CLIENT_SECRET=<secreto>
```

La credencial de la API se crea **una vez** en API-Auth:

```bash
php artisan passport:client --client --name="API-Maestros2"
```

Solo hace falta con las estrategias `introspection` y `hybrid`, porque la API debe identificarse ante
API-Auth para preguntar si un token fue revocado.

Para revisar que todo esté bien:

```bash
php artisan ubo:auth:check
```

## 2. Proteger rutas

```php
Route::middleware('ubo.auth')->get(...);                               // cualquier token válido
Route::middleware('ubo.auth:geografia:leer')->get(...);                // exige un scope
Route::middleware('ubo.auth:geografia:leer,sistemas:leer')->get(...);  // exige TODOS los scopes
```

Después del middleware, el token verificado está disponible en la petición:

```php
use UBO\Common\Auth\Domain\VerifiedToken;

$token = VerifiedToken::from($request);
$token->clientId;          // client_id de API-Auth: identifica al sistema que llama
$token->scopes;            // ['geografia:leer']
$token->hasScope('x');     // bool
$token->expiresAt;         // DateTimeImmutable
```

## 3. Qué valida el middleware

| Paso | Revisión | Si falla |
|---|---|---|
| 1 | Viene `Authorization: Bearer <token>` | 401 `InvalidToken` |
| 2 | Firma RS256 de API-Auth. Si falla, vuelve a descargar la clave una vez (rotación) | 401 |
| 3 | Vigencia `exp`/`nbf`/`iat`, con `UBO_AUTH_LEEWAY` segundos de tolerancia | 401 |
| 4 | Es un token M2M (salvo `UBO_AUTH_ALLOW_USER_TOKENS=true`) | 401 |
| 5 | Si hay `UBO_AUTH_API`: el token de sistema fue emitido para esta API (su nombre está en `aud`) | 401 |
| 6 | No está revocado (estrategias `introspection` y `hybrid`) | 401 |
| 7 | Tiene todos los scopes de la ruta | 403 `InsufficientScope` |
| — | API-Auth no responde cuando hay que consultarlo | 503 `AuthServerUnavailable` |

Las excepciones heredan de las de Laravel (`AuthenticationException` y `AuthorizationException`;
`InsufficientScope::scopes()` devuelve los scopes faltantes), así que cada API las presenta con su
propio formato de error. El módulo [Problems](problems.md) las presenta en RFC 9457 con los códigos
`NO_AUTENTICADO`, `SCOPE_INSUFICIENTE` y `AUTENTICACION_NO_DISPONIBLE`.

### Tokens emitidos para otra API

API-Auth pone el nombre de la API en el `aud` de los tokens que emite para ella: `["<client id>", "api-maestros"]`.
Con `UBO_AUTH_API=api-maestros`, el middleware rechaza los tokens de sistema que no nombran a esta API,
aunque traigan un scope con el mismo nombre. Así, un token filtrado no sirve en otra API, sin depender de
que los nombres de scope sean únicos. Sin `UBO_AUTH_API` no se revisa el `aud` (comportamiento de la 2.0).
Los tokens de usuario no se revisan contra el nombre de la API.

En los tests, `TokenFactory::forClient()` emite los tokens para la API configurada en `ubo-auth.api`.
Para simular un token de otra API: `forClient('SIS', ['geografia:leer'], api: 'api-matricula')`; sin API: `api: ''`.

**Importante:** si la API no fuerza respuestas JSON, Laravel intenta redirigir los 401 a la ruta
`login`. En las APIs, haz que las rutas `api/*` respondan JSON (como ya hacen API-Auth y Maestros2).

## 4. Estrategias

| Estrategia | Cómo valida | Llamadas a API-Auth | Revocación |
|---|---|---|---|
| `jwt` | Firma y vigencia, localmente | Ninguna (solo descarga la clave una vez) | No la detecta hasta que el token vence (≤ 60 min) |
| `introspection` | Pregunta a API-Auth | Una por token cada `UBO_AUTH_INTROSPECTION_TTL` s | Con ≤ 30 s de retraso |
| `hybrid` (recomendada) | Local primero; si pasa, pregunta a API-Auth | Solo para tokens bien firmados | Con ≤ 30 s de retraso |

Con `jwt` y `UBO_AUTH_PUBLIC_KEY` definida, la API **no hace ninguna llamada de red**.

## 5. Tests en la API

```php
use UBO\Common\Auth\Testing\TokenFactory;

beforeEach(fn () => $this->tokens = TokenFactory::fake());

test('un sistema con el scope accede', function () {
    $token = $this->tokens->forClient('client-id', ['geografia:leer']);

    $this->withToken($token)->getJson('/api/v1/geografia/paises')->assertOk();
});
```

| Método | Token |
|---|---|
| `forClient($clientId, $scopes, $ttl)` | M2M válido |
| `forUser($userId, $clientId, $scopes)` | De usuario |
| `expired($clientId, $scopes)` | Vencido |
| `forged($clientId, $scopes)` | Firmado con una clave que no es la de API-Auth |
| `revoke($token)` | Marca un token como revocado (lo ven `introspection` e `hybrid`) |

Los tokens pasan por los validadores reales. API-Auth no necesita estar levantado.

## 6. Token propio de la aplicación

`ServiceTokenProvider` obtiene tokens de API-Auth para **esta** aplicación (client credentials), los
guarda en caché y los renueva 60 s antes de que venzan. Lo usan la introspección y el módulo
ApiClient:

```php
app(UBO\Common\Auth\Contracts\ServiceTokenProvider::class)->token(['geografia:leer']);
```
