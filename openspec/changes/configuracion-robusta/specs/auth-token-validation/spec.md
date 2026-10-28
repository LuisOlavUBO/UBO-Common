# Spec Delta

## ADDED Requirements

### Requirement: Valores de configuración vacíos usan el valor por defecto
Un valor de Auth vacío, nulo o no numérico MUST usar su valor por defecto: `timeout` 5,
`cache.public_key_ttl` 86400, `cache.introspection_ttl` 30, `cache.service_token_margin` 60,
`cache.prefix` `ubo-auth:`, `strategy` `hybrid` y `leeway` 10. Los mismos MUST aplicarse si el valor es
`<= 0`, salvo `leeway`, que acepta `0`.

#### Scenario: Timeout vacío en el .env
- **WHEN** el `.env` tiene `UBO_AUTH_TIMEOUT=` sin valor
- **THEN** las llamadas a API-Auth usan un timeout de 5 segundos

#### Scenario: TTL de la clave en cero
- **WHEN** la configuración tiene `cache.public_key_ttl = 0`
- **THEN** la clave pública se guarda en caché 86400 segundos

#### Scenario: Tolerancia de reloj en cero
- **WHEN** el `.env` tiene `UBO_AUTH_LEEWAY=0`
- **THEN** la vigencia de los tokens se revisa sin tolerancia

#### Scenario: Estrategia vacía
- **WHEN** el `.env` tiene `UBO_AUTH_STRATEGY=` sin valor
- **THEN** se usa la estrategia `hybrid`

#### Scenario: Estrategia desconocida
- **WHEN** el `.env` tiene `UBO_AUTH_STRATEGY=oauth`
- **THEN** sigue fallando con `InvalidArgumentException` al resolver el validador

### Requirement: Rutas de los endpoints de API-Auth
Las rutas de los endpoints de API-Auth SHALL ser las del paquete, relativas a `UBO_AUTH_URL`: token
`/api/auth/oauth/token`, introspección `/api/auth/v1/introspect` y clave pública `/api/auth/v1/public-key`.
Solo MUST poder reemplazarse con `UBO_AUTH_ENDPOINT_TOKEN`, `UBO_AUTH_ENDPOINT_INTROSPECT` o
`UBO_AUTH_ENDPOINT_PUBLIC_KEY`; la clave `endpoints` de un archivo publicado se ignora.

#### Scenario: Archivo publicado con rutas de una versión anterior
- **WHEN** la aplicación publicó `ubo-auth.php` con `endpoints.token = '/oauth/token'`
- **THEN** los tokens se piden a `<UBO_AUTH_URL>/api/auth/oauth/token`

#### Scenario: Ruta reemplazada por variable
- **WHEN** el `.env` tiene `UBO_AUTH_ENDPOINT_INTROSPECT=/auth/v2/introspect`
- **THEN** la introspección se hace en `<UBO_AUTH_URL>/auth/v2/introspect`

#### Scenario: Variable de ruta vacía
- **WHEN** el `.env` tiene `UBO_AUTH_ENDPOINT_TOKEN=` sin valor
- **THEN** se usa la ruta del paquete `/api/auth/oauth/token`

#### Scenario: Simulación de API-Auth en tests
- **WHEN** un test llama a `UboApi::fake()`
- **THEN** el endpoint de token simulado es el mismo que usa la librería para pedir tokens

### Requirement: Sin validación al registrar los módulos
La configuración de Auth MUST NOT validarse al registrar los módulos. Los valores incompletos o
inválidos se detectan al usarlos (al validar un token o pedir uno propio) o con `ubo:auth:check`.

#### Scenario: Build sin .env
- **WHEN** se ejecuta `composer install` en un build sin `UBO_AUTH_URL` ni credenciales
- **THEN** el descubrimiento de paquetes termina sin errores
