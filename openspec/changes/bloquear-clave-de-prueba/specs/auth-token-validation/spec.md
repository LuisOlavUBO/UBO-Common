# Spec Delta

## MODIFIED Requirements

### Requirement: Comando de diagnóstico
El comando `php artisan ubo:auth:check` SHALL mostrar la estrategia, la URL de API-Auth y el nombre de la
API, verificar que la clave pública se obtiene, es un PEM válido y, con `APP_ENV=production`, no es la de
prueba, y pedir un token propio de scope `introspect` si hay credenciales. MUST terminar con error si una
revisión falla o si la estrategia no es `jwt` y faltan credenciales.

#### Scenario: Todo configurado
- **WHEN** se ejecuta el comando con URL, credenciales válidas y API-Auth disponible
- **THEN** cada revisión muestra OK y el comando termina con éxito

#### Scenario: Estrategia hybrid sin credenciales
- **WHEN** la estrategia es `hybrid` y no hay `UBO_AUTH_CLIENT_ID` o `UBO_AUTH_CLIENT_SECRET`
- **THEN** el comando informa que la estrategia necesita credenciales y termina con error

#### Scenario: Estrategia jwt sin credenciales
- **WHEN** la estrategia es `jwt`, la clave pública es válida y no hay credenciales
- **THEN** el comando informa que las credenciales no son necesarias y termina con éxito

#### Scenario: Clave de prueba en producción
- **WHEN** se ejecuta el comando con `APP_ENV=production` y la clave pública es la de prueba
- **THEN** la revisión de la clave pública muestra FAIL indicando que es la clave de prueba y el comando termina con error
