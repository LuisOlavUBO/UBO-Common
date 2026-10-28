# Design

## Context

`TokenFactory` firma con `src/Auth/Testing/keys/test-private.key`, y `fake()` fija
`ubo-auth.public_key` con `test-public.key`. La clave pública que valida los tokens sale de
`PublicKeyProvider`: fija en configuración (`StaticPublicKeyProvider`) o descargada de API-Auth
(`HttpPublicKeyProvider`). Nada impide hoy que esa clave sea la de prueba fuera de los tests.

## Goals / Non-Goals

**Goals:**
- Que en producción ningún token firmado con la clave de prueba pueda aceptarse, venga la clave de donde venga.
- Que el error se note (log y `ubo:auth:check`) sin exponer el motivo al cliente.

**Non-Goals:**
- Sacar las claves de prueba del paquete: `TokenFactory` las necesita en las suites de las aplicaciones.
- Proteger entornos que no se declaran `production` (staging, QA).

## Decisions

### El chequeo se hace en el punto donde se usa la clave

`JwtValidator` revisa la clave que obtuvo de `PublicKeyProvider` (la de la caché y la refrescada) antes de
verificar la firma. Así cubre los dos orígenes sin cambiar ninguna interfaz pública (API-Maestros implementa
`TokenValidator` en sus tests, y agregar métodos a los contratos rompería esas implementaciones). Una
aplicación que reemplaza `TokenValidator` por completo queda fuera de este chequeo, como de todo el resto
de la validación del paquete.

**Alternativa considerada:** revisar en cada `PublicKeyProvider`. Duplica la lógica y deja fuera a un
proveedor implementado por una aplicación.

### La huella de la clave de prueba vive en `Auth/Testing/`

Una clase `@internal` junto a las claves (`Auth\Testing\TestKey`) sabe calcular la huella normalizada y
decir si un PEM es la clave pública de prueba. La usan `JwtValidator`, `TokenFactory::fake()` y
`CheckCommand`, así la definición de "clave de prueba" está en un solo lugar.

### Comparación por huella normalizada

Se compara el SHA-256 del contenido de la clave sin espacios, sin saltos de línea y sin las líneas
`-----BEGIN/END-----` contra el de `test-public.key`. Un `\n` literal en el `.env`, CRLF o espacios al
final no evitan la detección.

### Fallar con `AuthServerUnavailable` (503)

Se reutiliza la excepción que ya significa "no se puede verificar el token": Problems ya la presenta como
503 `AUTENTICACION_NO_DISPONIBLE` sin enviar el motivo al cliente. No hace falta una excepción pública nueva
ni un mapeo nuevo.

### El validador registra el motivo en el log

La spec exige el log aunque la aplicación no use Problems, así que `JwtValidator` registra un error
(`critical`: es una configuración insegura en producción) antes de lanzar la excepción, con el logger de
Laravel inyectado. Si además está Problems, el motivo aparece también como advertencia; la duplicación se
acepta para no depender de que la app registre `ProblemRenderer`.

**Alternativa considerada:** un 500 genérico. Lo presentaría como `ERROR_INTERNO` y el motivo solo quedaría
en el log de excepciones de Laravel; el 503 deja claro que es la autenticación la que no está disponible.

### "Producción" es `app()->isProduction()`

Es decir, `APP_ENV=production`. Es la convención de Laravel y no agrega configuración nueva.

## Risks / Trade-offs

- [Un staging real con la clave de prueba no queda protegido] → Se documenta; `ubo:auth:check` lo
  muestra solo en producción. Una opción para bloquear también otros entornos puede venir en otro cambio.
- [Una app que corre sus tests con `APP_ENV=production`] → `TokenFactory::fake()` falla con un mensaje
  claro. Las dos APIs conocidas usan `APP_ENV=testing` en `phpunit.xml`.
- [Costo por petición] → La huella se calcula una vez por clave y proceso; el resultado se guarda junto a
  la clave ya obtenida.
