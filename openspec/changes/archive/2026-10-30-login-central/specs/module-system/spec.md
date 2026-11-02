# Spec Delta

## MODIFIED Requirements

### Requirement: Catálogo de módulos con interruptor
El paquete SHALL ofrecer los módulos `auth`, `api-client`, `tracing`, `problems`, `health`, `sqlserver` y
`login`, y MUST registrar solo los que estén activos en `ubo-common.modules`. Cada interruptor se
controla con la variable `UBO_COMMON_<MODULO>` (por ejemplo `UBO_COMMON_API_CLIENT`).

#### Scenario: Módulo desactivado
- **WHEN** la aplicación define `UBO_COMMON_TRACING=false`
- **THEN** el módulo `tracing` no se registra: no agrega su middleware, su configuración ni sus bindings

#### Scenario: Módulo sin interruptor definido
- **WHEN** la configuración `ubo-common.modules` no tiene la clave de un módulo
- **THEN** ese módulo se registra (se considera activo)

### Requirement: Módulos activos por defecto
Los módulos `auth`, `api-client`, `tracing`, `problems` y `health` SHALL venir activos por defecto. Los
módulos `sqlserver` y `login` MUST venir desactivados por defecto: el primero cambia el comportamiento de las
conexiones de la aplicación y el segundo registra rutas, un guard y habilidades de `Gate`.

#### Scenario: Configuración por defecto
- **WHEN** la aplicación no define ninguna variable `UBO_COMMON_*`
- **THEN** se registran todos los módulos excepto `sqlserver` y `login`

#### Scenario: Configuración publicada con una versión anterior
- **WHEN** la aplicación publicó `config/ubo-common.php` antes de existir el módulo `login`
- **THEN** el módulo `login` sigue desactivado

#### Scenario: Activar el login
- **WHEN** la aplicación define `UBO_COMMON_LOGIN=true`
- **THEN** se registran las rutas, el guard `ubo` y los alias `ubo.login` y `ubo.role`
