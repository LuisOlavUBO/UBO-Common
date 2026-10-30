# Módulo Login

Permite que un **monolito Laravel** autentique a sus usuarios en **IAM (API-Auth)**: la persona escribe su
contraseña solo en IAM, y el monolito recibe un token con sus **roles** y **scopes** en esta aplicación. Usa
Authorization Code con PKCE (`S256`) y un cliente confidencial.

```
Navegador ──GET /iam/login──► monolito ──302──► IAM /api/auth/oauth/authorize (login de IAM)
Navegador ◄──302 /iam/callback?code&state── IAM
Monolito ──POST /api/auth/oauth/token (code + code_verifier + secreto)──► IAM  (servidor a servidor)
Monolito: valida el token, abre la sesión, redirige a la página que la persona pedía
```

El módulo viene **apagado**. Registra rutas, el guard `ubo`, los alias `ubo.login` y `ubo.role`, y una regla de
`Gate` que convierte los scopes de la persona en habilidades.

El token de acceso de IAM vive 60 minutos. El módulo lo **renueva solo** con el refresh token, sin que la
persona lo note, así que una sesión activa no se corta ni pierde un formulario a medio llenar (ver la sección 5).

## 1. Alta en IAM

Una vez, para cada monolito (ejemplo: la aplicación `portal`). Ver el README de API-Auth para los roles y scopes:

```bash
php artisan oauth:application portal "Portal de la UBO"
php artisan oauth:client portal-web --application=portal --type=authorization-code --redirect=https://portal.ubo.cl/iam/callback
```

El comando muestra el `client id` y el secreto (solo esa vez). La `--redirect` debe ser **exactamente** la
dirección del callback del monolito. Es un cliente distinto del M2M (`client-credentials`) de `ubo-auth`: el de
login no sirve para pedir tokens de sistema, y el M2M no es necesario para entrar.

## 2. Configuración

```dotenv
UBO_COMMON_LOGIN=true
UBO_AUTH_URL=http://api-auth                    # interna: de servidor a servidor
UBO_LOGIN_PUBLIC_URL=https://iam.ubo.cl         # pública: la que abre el navegador
UBO_LOGIN_CLIENT_ID=<client id del cliente de login>
UBO_LOGIN_CLIENT_SECRET=<secreto>
APP_URL=https://portal.ubo.cl                   # la dirección pública de este monolito
```

| Variable | Por defecto | Uso |
|---|---|---|
| `UBO_LOGIN_PUBLIC_URL` | `UBO_AUTH_URL` | Dirección de IAM para el navegador (login y logout) |
| `UBO_LOGIN_CLIENT_ID` / `UBO_LOGIN_CLIENT_SECRET` | — | Credenciales del cliente de login |
| `UBO_LOGIN_REDIRECT_URI` | `APP_URL` + `/<prefijo>/callback` | La `redirect_uri` registrada en IAM |
| `UBO_LOGIN_ROUTE_PREFIX` | `iam` | Prefijo de las rutas: `/iam/login`, `/iam/callback`, `/iam/logout` |
| `UBO_LOGIN_HOME` | `/` | Destino cuando no hay página a la que volver |
| `UBO_LOGIN_LOGOUT_RETURN` | `APP_URL` + `UBO_LOGIN_HOME` | A dónde vuelve la persona después del logout de IAM |
| `UBO_LOGIN_PROMPT` | — | `login` pide la clave en cada login, aunque la persona tenga sesión en IAM |
| `UBO_LOGIN_STATE_TTL` | 600 s | Lo que puede tardar la persona en volver de IAM |
| `UBO_LOGIN_STORE` | el almacén de caché de la aplicación | Almacén donde viven los tokens (la bóveda, sección 5): compartido entre servidores y con bloqueos |
| `UBO_LOGIN_REFRESH_MARGIN` | 60 s | Cuánto antes de vencer se renueva el token de acceso |
| `UBO_LOGIN_REVALIDATE` | 300 s | Cada cuánto se pregunta a IAM si el token sigue activo; `0` lo apaga |
| `UBO_LOGIN_MAX_AGE` | 43200 s (12 h) | Duración máxima de la sesión desde el login, por mucho que se renueve; `0` la deja sin tope |
| `UBO_LOGIN_REFRESH_TTL` | 7200 s (2 h) | Inactividad que tolera la bóveda: lo que tolera el refresh token de IAM |

Las variables vacías usan el valor por defecto. Si falta alguna al usar las rutas, el error la nombra
(`UBO_LOGIN_CLIENT_SECRET must be configured...`); el módulo no valida nada al registrarse, así que un build
sin `.env` funciona. `APP_URL` debe ser la dirección pública (con proxies, la de afuera): la `redirect_uri`
sale de ahí y **nunca** de la cabecera `Host`.

Requisitos de la aplicación:

- El módulo `auth` activo (viene activo). El login **no** usa credenciales M2M ni consulta la introspección:
  valida el token que IAM acaba de entregarle con la clave pública de IAM (se descarga sola de
  `UBO_AUTH_URL`, o se fija con `UBO_AUTH_PUBLIC_KEY`).
- Una sesión **de servidor** (`SESSION_DRIVER=database`, `redis`, `file`...). Con `cookie` el módulo se niega a
  entrar: la sesión viajaría en el navegador. Conviene `SESSION_ENCRYPT=true`.
- Un almacén de caché **compartido entre los servidores** y con **bloqueos** para la bóveda de tokens (`redis`
  o `database`; no `array`, y `file` solo con un servidor): igual que con `SESSION_DRIVER`. Si la bóveda no es
  compartida, la persona que cae en otro servidor no encuentra su entrada y vuelve a pasar por IAM (no es un
  error, pero se nota). `APP_KEY` también debe ser la misma en todos los servidores: la bóveda va cifrada con
  ella.
- `SESSION_SAME_SITE=lax` (el valor por defecto de Laravel). Con `strict` el navegador no envía la cookie en
  la redirección que vuelve de IAM y el callback falla.
- En desarrollo, IAM y el monolito en `localhost` comparten cookies (no se aíslan por puerto): usa nombres de
  cookie distintos (`SESSION_COOKIE`) o dominios `*.localhost`.

## 3. Proteger rutas

```php
Route::middleware('ubo.login')->group(function () {
    Route::get('/informes', ...);                                  // cualquier persona con sesión
    Route::get('/informes/nuevo', ...)->middleware('can:informes:crear');   // por scope
    Route::get('/admin', ...)->middleware('ubo.role:coordinador,admin');    // por rol (uno de la lista)
});
```

- **`ubo.login`**: sin sesión, una petición web va al login y vuelve a la página que pedía; una petición JSON
  recibe 401. Dentro de la ruta, `ubo` pasa a ser el guard por defecto (`auth()->user()`, `Gate`, `@can`).
- **`ubo.role:a,b`**: deja pasar a quien tiene **al menos uno** de los roles, con el nombre exacto (distingue
  mayúsculas), y responde 403 al resto.
- Los **scopes** del token son habilidades de `Gate`: `@can('geografia:leer')`, `$this->authorize(...)`,
  `can:...`. Lo que no es un scope de la persona se deja a tus políticas y `Gate::define`: el módulo nunca
  niega.

```php
use Illuminate\Support\Facades\Auth;

$user = Auth::guard('ubo')->user();   // UboUser
$user->getAuthIdentifier();           // el "sub" del token: id de la persona en IAM
$user->roles;                         // ['coordinador']
$user->scopes;                        // ['geografia:leer', ...]
$user->audiences;                     // [client id, 'api-maestros', ...]
$user->hasRole('coordinador');
$user->hasScope('geografia:leer');
```

El guard `ubo` existe sin tocar `config/auth.php` y no cambia el guard por defecto. Para usarlo como `web`:
`'guards' => ['web' => ['driver' => 'ubo']]`. `UboUser` no guarda el token y no tiene nombre ni correo: hoy el
token solo trae el id.

**Los roles y scopes son los del último token emitido**: se actualizan con cada renovación (cada ~59 minutos).
Un rol que IAM quita se nota en la siguiente renovación, y uno que se agrega, también; la persona que pierde
todos sus roles queda fuera. Los scopes nunca crecen en una renovación, solo se descartan.

## 4. Rutas y errores

| Ruta | Nombre | Qué hace |
|---|---|---|
| `GET /iam/login` | `ubo.login` | Empieza el login. `?redirect=/ruta` (solo de esta aplicación) y `?reauth=1` (pide la clave de nuevo) |
| `GET /iam/callback` | `ubo.login.callback` | Termina el login |
| `POST /iam/logout` | `ubo.logout` | Cierra la sesión (con CSRF). `scope=local` cierra solo aquí |

Los errores se lanzan como excepciones HTTP, y la aplicación decide cómo mostrarlos:

| Excepción | Status | Cuándo |
|---|---|---|
| `LoginFailed` | 400 | El callback no corresponde a un login iniciado desde ese navegador (`state`), el código fue rechazado o el token no sirve |
| `LoginFailed` | 403 | IAM devolvió `error` (por ejemplo `access_denied`) |
| `NoAccess` | 403 | La persona se autenticó pero no tiene ningún rol en esta aplicación |
| `AuthServerUnavailable` | 503 | IAM no responde o su circuito está abierto |

## 5. La sesión de tokens

Los tokens (acceso y refresh) **no viven en la sesión de Laravel**, sino en una **bóveda** en el almacén de
caché `UBO_LOGIN_STORE`: un JSON cifrado con `APP_KEY`, bajo un identificador aleatorio de 40 caracteres que es
lo único que guarda la sesión (clave `ubo_login`). Cada login crea un identificador nuevo y el anterior deja
de servir. Se hace así porque IAM rota el refresh token (cada uno sirve **una vez**) y al renovar **revoca
al instante el token de acceso anterior**: con los tokens en la sesión, dos peticiones en paralelo se pisarían
al guardar y una dejaría a la persona con un refresh token ya usado. En la bóveda hay una sola copia, que se
escribe con un bloqueo.

El guard `ubo` hace lo siguiente en cada petición, en este orden:

1. **Tope**: si pasaron `UBO_LOGIN_MAX_AGE` segundos desde el login, la sesión termina (IAM no tiene un tope
   propio: cada renovación suma 120 minutos, y una pestaña abierta tendría sesión para siempre).
2. **Renovación**: si al token le quedan `UBO_LOGIN_REFRESH_MARGIN` segundos o menos, lo renueva con el refresh
   token bajo `Cache::lock`. Con varias peticiones a la vez renueva una sola y las demás usan el resultado. El
   token nuevo se valida como el del login y debe ser de la misma persona; sus roles, scopes y audiencias
   reemplazan a los guardados.
3. **Revalidación**: cada `UBO_LOGIN_REVALIDATE` segundos pregunta a IAM, con la estrategia de `UBO_AUTH_STRATEGY`,
   si el token sigue activo, para enterarse de un logout hecho en otro sistema. Solo con `introspection` o
   `hybrid` y **credenciales M2M** (`UBO_AUTH_CLIENT_ID`/`UBO_AUTH_CLIENT_SECRET`); con `jwt` o sin ellas no se
   revalida, y el cierre de sesión en otro sistema se nota cuando se renueva el token (≤ 60 minutos).

La sesión **termina** (el guard devuelve `null`, la bóveda se borra y la siguiente petición pasa de nuevo por
IAM) cuando:

| Motivo | Detalle |
|---|---|
| IAM rechaza el refresh token (`invalid_grant`) | Salvo que otra petición ya lo haya renovado: entonces se usa su resultado |
| El token renovado no trae ningún rol | La persona perdió el acceso; IAM le muestra su página de "sin acceso" |
| El token renovado es de otra persona o cliente, o no es válido | |
| El token de acceso venció y no hay refresh token | IAM no entregó uno en el login: la sesión dura lo que el token |
| Se alcanzó `UBO_LOGIN_MAX_AGE` | |
| IAM informa en la revalidación que el token ya no está activo | |
| La entrada de la bóveda desapareció | Caché vaciada, expirada (`UBO_LOGIN_REFRESH_TTL`) o `APP_KEY` distinta |

**Si IAM no responde** al renovar (error de conexión, circuito abierto, 5xx) o la rechaza por otro motivo que
`invalid_grant` (por ejemplo 429), **la sesión no termina**: se sigue con el token vigente mientras no venza,
y si ya venció el guard devuelve `null` sin borrar la bóveda, así que la persona va a IAM (que, si está caído,
responde 503 en el callback) y la siguiente petición con IAM disponible renueva. La revalidación también
tolera que IAM no responda. Cada consulta al guard con IAM caído vuelve a intentarlo (el circuito de API-Auth
evita la carga). IAM limita `/api/auth/oauth/token` a 120 solicitudes por minuto **por cliente**, compartidas
por todas las personas del monolito: un pico de renovaciones puede recibir 429, que no termina la sesión (hay
60 segundos de margen).

Si la caché falla justo al guardar un token renovado, el refresh token ya estaba gastado: la persona vuelve a
pasar por IAM y queda un `warning` en el log.

## 6. Logout

`POST /iam/logout` (usa un formulario con `@csrf`):

1. Revoca en IAM el token **vigente de la bóveda** (el ya renovado: el que entregó el login hace horas ya está
   revocado) y borra la entrada. Si la revocación falla, se sigue y queda un `warning` en el log.
2. Cierra la sesión local y regenera el token CSRF.
3. Manda a la persona a `IAM /api/auth/logout`, que cierra su sesión de IAM y **revoca sus tokens en todos
   los sistemas**; IAM la devuelve a `UBO_LOGIN_LOGOUT_RETURN`. Ese `return` debe tener el mismo origen que la
   `redirect_uri` registrada.

Con `scope=local` solo se revoca el token de esta sesión y se vuelve a `UBO_LOGIN_HOME`, sin pasar por IAM: las
sesiones de la persona en otros sistemas siguen.

## 7. Tests de tu aplicación

```php
use UBO\Common\Login\Testing\UboLogin;

UboLogin::actingAs(id: '42', roles: ['coordinador'], scopes: ['geografia:leer']);

$this->get('/informes')->assertOk();
```

No llama a IAM: deja la sesión en la bóveda (sin refresh token) y el guard no la renueva ni la revalida, así que
los tests con `travel()` solo ven terminar la sesión al vencer el token (`ttl`, 3600 s por defecto) o llegar a
`UBO_LOGIN_MAX_AGE`. Falla con `LogicException` en producción. Para probar el flujo completo con IAM falso,
`Http::fake()` del endpoint `/api/auth/oauth/token` y un token de `TokenFactory::fake()->forUser(...)`:
mira `tests/Login/CallbackTest.php` en esta librería.

## 8. Seguridad

- `state` aleatorio de un solo uso, ligado a la sesión del navegador (frena el *login CSRF*), y PKCE `S256`.
- El id de sesión se regenera al entrar, y el token CSRF también.
- Solo se vuelve a páginas de esta aplicación; el `return` del logout sale de la configuración.
- Los tokens quedan cifrados en la bóveda del servidor (`UBO_LOGIN_STORE`) y no se envían al navegador: la
  sesión solo guarda un identificador aleatorio, que cambia en cada login y deja de servir al cerrar la sesión.
- El logout revoca el token vigente, y la sesión termina sola por el tope (`UBO_LOGIN_MAX_AGE`), por la
  revalidación contra IAM y cuando IAM rechaza el refresh token.
- IAM limita `/api/auth/oauth/token` a 120 solicitudes por minuto por cliente: un pico de logins puede recibir
  429, que llega como `LoginFailed` (400); en una renovación no termina la sesión.
