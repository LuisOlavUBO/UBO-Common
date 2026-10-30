# Proposal

## Why

`composer.json` promete compatibilidad con PHP 8.3+, Laravel 12|13 y Guzzle 7|8, pero solo se prueba con la
combinación del lock y en la máquina de quien publica: no hay CI, análisis estático ni cobertura (hallazgo
12). El paquete distribuye además archivos que no necesita (hallazgo 13), y `JwtValidator` vuelve a parsear
la clave pública en cada petición (hallazgo 15).

## What Changes

- CI en GitHub Actions para cada push y PR: Pint, PHPStan con Larastan y la suite de Pest con cobertura,
  en una matriz de PHP 8.3/8.4 × Laravel 12/13 × dependencias mínimas y estables.
- PHPStan con Larastan como dependencia de desarrollo, con un baseline para los errores existentes.
- `.gitattributes` con `export-ignore` para lo que no forma parte del paquete distribuido.
- La clave pública parseada se reutiliza dentro del mismo proceso mientras su contenido no cambie.
- Sin cambios de comportamiento observable: este cambio no tiene specs (`skip_specs: true`).

## Capabilities

### New Capabilities

Ninguna.

### Modified Capabilities

Ninguna: no cambia ningún requisito.

## Impact

- Archivos nuevos: `.github/workflows/ci.yml`, `phpstan.neon` y `phpstan-baseline.neon`.
- `composer.json`: `larastan/larastan` en `require-dev` y scripts `analyse` y `test:coverage`.
- `.gitattributes`: reglas `export-ignore`.
- Código: `JwtValidator` (memoización de la clave).
- Puede obligar a subir versiones mínimas en `composer.json` si la matriz con `--prefer-lowest` falla.
- Versión: 3.1.0 (minor), o patch si se publica solo.
