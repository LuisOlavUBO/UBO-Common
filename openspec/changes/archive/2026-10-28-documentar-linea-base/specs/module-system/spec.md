# Spec Delta

## Purpose

Permite que una aplicación Laravel instale UBO.Common una sola vez y cargue solo los módulos que usa,
cada uno con su propia configuración publicable y valores por defecto.

## ADDED Requirements

### Requirement: Registro automático del paquete
El paquete SHALL registrarse en la aplicación mediante el descubrimiento automático de paquetes de
Laravel, sin que la aplicación tenga que declarar un service provider.

#### Scenario: Instalación con Composer
- **WHEN** una aplicación instala `ubo/common`
- **THEN** los módulos activos quedan registrados al arrancar la aplicación sin configuración adicional

### Requirement: Catálogo de módulos con interruptor
El paquete SHALL ofrecer los módulos `auth`, `api-client`, `tracing`, `problems`, `health` y
`sqlserver`, y MUST registrar solo los que estén activos en `ubo-common.modules`. Cada interruptor se
controla con la variable `UBO_COMMON_<MODULO>` (por ejemplo `UBO_COMMON_API_CLIENT`).

#### Scenario: Módulo desactivado
- **WHEN** la aplicación define `UBO_COMMON_TRACING=false`
- **THEN** el módulo `tracing` no se registra: no agrega su middleware, su configuración ni sus bindings

#### Scenario: Módulo sin interruptor definido
- **WHEN** la configuración `ubo-common.modules` no tiene la clave de un módulo
- **THEN** ese módulo se registra (se considera activo)

### Requirement: Módulos activos por defecto
Los módulos `auth`, `api-client`, `tracing`, `problems` y `health` SHALL venir activos por defecto. El
módulo `sqlserver` MUST venir desactivado por defecto, porque cambia el comportamiento de las
conexiones de la aplicación.

#### Scenario: Configuración por defecto
- **WHEN** la aplicación no define ninguna variable `UBO_COMMON_*`
- **THEN** se registran todos los módulos excepto `sqlserver`

### Requirement: Configuración propia por módulo
Cada módulo SHALL tener su archivo de configuración `ubo-<modulo>` con valores por defecto, que se
fusiona con la configuración de la aplicación al registrarse el módulo.

#### Scenario: Aplicación sin configuración publicada
- **WHEN** la aplicación no publicó el archivo de configuración de un módulo activo
- **THEN** el módulo funciona con los valores por defecto del paquete y las variables de `.env`

### Requirement: Fusión superficial de la configuración publicada
La fusión entre la configuración publicada por la aplicación y la del paquete SHALL ser solo de primer
nivel: una clave de primer nivel presente en el archivo publicado reemplaza completa a la del paquete.
Nota: comportamiento conocido (hallazgo 4 del diagnóstico de arquitectura).

#### Scenario: Arreglo anidado publicado con una versión anterior
- **WHEN** la aplicación publicó `ubo-auth.php` con un arreglo `endpoints` de una versión anterior
- **THEN** se usan los `endpoints` del archivo publicado y no los de la versión instalada del paquete

### Requirement: Publicación de la configuración
Cada módulo SHALL permitir publicar su configuración con `php artisan vendor:publish --tag=ubo-<modulo>-config`,
y el paquete MUST permitir publicar los interruptores con la etiqueta `ubo-common-config`. La
publicación solo se ofrece al ejecutar en consola.

#### Scenario: Publicar la configuración de un módulo
- **WHEN** se ejecuta `php artisan vendor:publish --tag=ubo-auth-config`
- **THEN** se copia `config/ubo-auth.php` a la aplicación con los valores por defecto comentados

### Requirement: Dependencias entre módulos por contratos
Un módulo SHALL depender de otro solo a través de sus contratos públicos o excepciones públicas. Un
módulo que necesita otro módulo desactivado MUST fallar con un error explícito al usarse, y un módulo
que solo usa otro de forma opcional MUST seguir funcionando sin él.

#### Scenario: ApiClient sin Auth
- **WHEN** el módulo `auth` está desactivado y la aplicación llama a una API con `UboApi::call`
- **THEN** la llamada falla con un `LogicException` que indica que `api-client` necesita el módulo `auth`

#### Scenario: Llamadas salientes sin Tracing
- **WHEN** el módulo `tracing` está desactivado
- **THEN** Auth y ApiClient hacen sus llamadas sin el header `traceparent` y sin fallar
