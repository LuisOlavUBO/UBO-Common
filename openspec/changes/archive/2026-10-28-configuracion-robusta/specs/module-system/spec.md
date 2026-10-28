# Spec Delta

## RENAMED Requirements

- FROM: `### Requirement: Fusión superficial de la configuración publicada`
- TO: `### Requirement: Fusión profunda de la configuración publicada`

## MODIFIED Requirements

### Requirement: Fusión profunda de la configuración publicada
La configuración publicada por la aplicación SHALL fusionarse en profundidad con la del paquete: en los
arreglos asociativos anidados, una clave que falta en el archivo publicado MUST tomarse del paquete y una
clave presente MUST conservar el valor de la aplicación. Una lista se reemplaza completa.

#### Scenario: Arreglo anidado publicado con una versión anterior
- **WHEN** la aplicación publicó `ubo-auth.php` con un arreglo `cache` que no tiene `service_token_margin`
- **THEN** `cache.service_token_margin` toma el valor del paquete y las demás claves de `cache` conservan las de la aplicación

#### Scenario: Valor anidado personalizado
- **WHEN** la aplicación publicó `ubo-apis.php` con `defaults.timeout = 30`
- **THEN** se usa `30` y las demás claves de `defaults` se toman del paquete

#### Scenario: Interruptor nuevo con ubo-common publicado
- **WHEN** la aplicación publicó `ubo-common.php` antes de que existiera un módulo nuevo
- **THEN** el interruptor de ese módulo toma el valor por defecto del paquete

#### Scenario: Configuración en caché
- **WHEN** la aplicación ejecutó `php artisan config:cache`
- **THEN** se usa la configuración en caché tal como quedó, sin volver a fusionar
