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

> **Esta es la primera parte del módulo.** El token de acceso vive 60 minutos y **no se renueva**: al vencer,
> la sesión del monolito termina y el siguiente acceso pasa de nuevo por IAM (sin pedir la clave si la sesión
> de IAM sigue abierta). Una persona que esté llenando un formulario largo puede perderlo al enviarlo. La
> renovación con refresh token, la revalidación contra IAM y el tope de sesión vienen en la segunda parte:
> hasta entonces conviene usarlo solo en desarrollo.

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

Las variables vacías usan el valor por defecto. Si falta alguna al usar las rutas, el error la nombra
(`UBO_LOGIN_CLIENT_SECRET must be configured...`); el módulo no valida nada al registrarse, así que un build
sin `.env` funciona. `APP_URL` debe ser la dirección pública (con proxies, la de afuera): la `redirect_uri`
sale de ahí y **nunca** de la cabecera `Host`.

Requisitos de la aplicación:

- El módulo `auth` activo (viene activo). El login **no** usa credenciales M2M ni consulta la introspección:
  valida el token que IAM acaba de entregarle con la clave pública de IAM (se descarga sola de
  `UBO_AUTH_URL`, o se fija con `UBO_AUTH_PUBLIC_KEY`).
- Una sesión **de servidor** (`SESSION_DRIVER=database`, `redis`, `file`...). Con `cookie` el módulo se niega a
  entrar: el token viajaría en el navegador. Conviene `SESSION_ENCRYPT=true`.
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

**Los roles y scopes son los de la última emisión del token** (hasta 60 minutos de antigüedad).

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

## 5. Logout

`POST /iam/logout` (usa un formulario con `@csrf`):

1. Revoca el token en IAM (si falla, se sigue y queda un `warning` en el log).
2. Cierra la sesión local y regenera el token CSRF.
3. Manda a la persona a `IAM /api/auth/logout`, que cierra su sesión de IAM y **revoca sus tokens en todos
   los sistemas**; IAM la devuelve a `UBO_LOGIN_LOGOUT_RETURN`. Ese `return` debe tener el mismo origen que la
   `redirect_uri` registrada.

Con `scope=local` solo se revoca el token de esta sesión y se vuelve a `UBO_LOGIN_HOME`, sin pasar por IAM: las
sesiones de la persona en otros sistemas siguen.

## 6. Tests de tu aplicación

```php
use UBO\Common\Login\Testing\UboLogin;

UboLogin::actingAs(id: '42', roles: ['coordinador'], scopes: ['geografia:leer']);

$this->get('/informes')->assertOk();
```

No llama a IAM. Falla con `LogicException` en producción. Para probar el flujo completo con IAM falso,
`Http::fake()` del endpoint `/api/auth/oauth/token` y un token de `TokenFactory::fake()->forUser(...)`:
mira `tests/Login/CallbackTest.php` en esta librería.

## 7. Seguridad

- `state` aleatorio de un solo uso, ligado a la sesión del navegador (frena el *login CSRF*), y PKCE `S256`.
- El id de sesión se regenera al entrar, y el token CSRF también.
- Solo se vuelve a páginas de esta aplicación; el `return` del logout sale de la configuración.
- El token de acceso queda en la sesión del servidor y no se envía al navegador.
- IAM limita `/api/auth/oauth/token` a 120 solicitudes por minuto por cliente: un pico de logins puede recibir
  429, que llega como `LoginFailed` (400).
