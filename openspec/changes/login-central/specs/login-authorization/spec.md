# Spec Delta

## Purpose

Permite que un monolito autorice con los permisos que IAM puso en el token de la persona: scopes con `Gate`,
`@can` y `can:`, roles por nombre y rutas que exigen una sesión de IAM.

## ADDED Requirements

### Requirement: Scopes como habilidades
Para un usuario del guard `ubo`, `Gate` (`can()`, `authorize()`, `@can`, el middleware `can:`) SHALL conceder la
habilidad cuyo nombre es un scope del token. Una habilidad que no es un scope del usuario MUST dejarse a las
demás reglas de la aplicación (políticas y `Gate::define`), nunca negarse por esta vía.

#### Scenario: Scope del token
- **WHEN** el usuario tiene el scope `geografia:leer` y la vista usa `@can('geografia:leer')`
- **THEN** el contenido se muestra

#### Scenario: Scope que no tiene
- **WHEN** el usuario no tiene el scope `geografia:escribir`
- **THEN** `Gate::allows('geografia:escribir')` es falso, salvo que otra regla de la aplicación lo conceda

#### Scenario: Política de la aplicación
- **WHEN** la aplicación define una política para una habilidad que no es un scope y el usuario la cumple
- **THEN** `Gate` la concede por esa política

#### Scenario: Visitante
- **WHEN** no hay usuario autenticado
- **THEN** los scopes no conceden ninguna habilidad

### Requirement: Middleware de autenticación
El alias `ubo.login` SHALL dejar pasar solo a quien tiene sesión del login vigente. Sin ella, MUST lanzar la
excepción de autenticación de Laravel apuntando a la ruta de login del módulo, de modo que una petición web sea
redirigida y recuerde la página pedida, y una petición JSON reciba 401. Dentro de la ruta, el guard `ubo` MUST ser el guard por defecto de la
petición, para que `auth()`, `Gate` y `@can` vean a la persona.

#### Scenario: Petición web sin sesión
- **WHEN** una persona sin sesión abre una ruta con `ubo.login`
- **THEN** es redirigida a la ruta de login y, al volver, llega a la página que pedía

#### Scenario: Petición JSON sin sesión
- **WHEN** una petición que espera JSON llega sin sesión a una ruta con `ubo.login`
- **THEN** responde 401

#### Scenario: Con sesión
- **WHEN** una persona con sesión vigente abre una ruta con `ubo.login`
- **THEN** la petición continúa y `Auth::guard('ubo')->user()` es su usuario

#### Scenario: Guard por defecto dentro de la ruta
- **WHEN** la aplicación tiene otro guard por defecto y una persona con sesión abre una ruta con `ubo.login`
- **THEN** `auth()->user()` y `Gate` dentro de la ruta usan a esa persona

### Requirement: Middleware de roles
El alias `ubo.role:a,b` SHALL dejar pasar a quien tiene al menos uno de los roles indicados, con el nombre exacto,
y MUST responder 403 a quien no tiene ninguno. Sin sesión del login, MUST comportarse como `ubo.login`.

#### Scenario: Tiene uno de los roles
- **WHEN** el usuario tiene el rol `coordinador` y la ruta usa `ubo.role:coordinador,admin`
- **THEN** la petición continúa

#### Scenario: No tiene ninguno
- **WHEN** el usuario tiene solo el rol `lector` y la ruta usa `ubo.role:coordinador,admin`
- **THEN** responde 403

#### Scenario: Capitalización distinta
- **WHEN** el usuario tiene el rol `Coordinador` y la ruta usa `ubo.role:coordinador`
- **THEN** responde 403
