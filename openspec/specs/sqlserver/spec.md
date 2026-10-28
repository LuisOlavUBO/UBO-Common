# sqlserver Specification

## Purpose

Evita que SQL Server lea mal las fechas que envía Laravel cuando el login tiene idioma español, fijando
el formato de fecha de cada conexión `sqlsrv`.

## Requirements
### Requirement: Formato de fecha en cada conexión sqlsrv
Con el módulo activo, cada conexión `sqlsrv` SHALL ejecutar `SET DATEFORMAT <formato>` al abrirse, fuera
de una sentencia preparada para que dure toda la sesión. Las conexiones ya abiertas al arrancar el módulo
MUST ajustarse también.

#### Scenario: Conexión nueva
- **WHEN** el módulo está activo y se abre una conexión `sqlsrv`
- **THEN** se ejecuta `SET DATEFORMAT ymd` en esa conexión

#### Scenario: Conexión abierta antes del arranque
- **WHEN** una conexión `sqlsrv` ya estaba abierta cuando arrancó el módulo
- **THEN** también se le ejecuta `SET DATEFORMAT ymd`

### Requirement: Solo conexiones sqlsrv
Las conexiones de otros drivers (sqlite, mysql, pgsql) MUST NOT modificarse.

#### Scenario: Conexión sqlite
- **WHEN** el módulo está activo y se abre una conexión `sqlite`
- **THEN** no se ejecuta ninguna sentencia adicional

### Requirement: Formato configurable y validado
El formato SHALL leerse de `UBO_SQLSERVER_DATE_FORMAT` (`ymd` por defecto, sin distinguir mayúsculas) y
MUST ser uno de `mdy`, `dmy`, `ymd`, `ydm`, `myd` o `dym`. Un valor distinto MUST lanzar
`InvalidArgumentException` al abrir la conexión, sin enviarse a SQL Server.

#### Scenario: Formato inválido
- **WHEN** `UBO_SQLSERVER_DATE_FORMAT=yyyy-mm-dd` y se abre una conexión `sqlsrv`
- **THEN** se lanza `InvalidArgumentException` con los formatos permitidos

### Requirement: Desactivado por defecto
El módulo SHALL venir desactivado y activarse con `UBO_COMMON_SQLSERVER=true`.

#### Scenario: Sin activar
- **WHEN** la aplicación no define `UBO_COMMON_SQLSERVER` y abre una conexión `sqlsrv`
- **THEN** no se ejecuta `SET DATEFORMAT`
