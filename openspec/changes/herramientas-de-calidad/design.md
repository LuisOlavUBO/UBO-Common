# Design

## Context

- No existe `.github/`. Los scripts de Composer son `test` (Pest) y `lint` (Pint).
- `require-dev`: `orchestra/testbench ^10|^11`, `pestphp/pest ^3.8|^4.0`, `laravel/pint`, `barryvdh/laravel-ide-helper`.
- `.gitattributes` solo tiene `* text=auto eol=lf`.
- `JwtValidator::signedBy()` crea `InMemory::plainText($publicKey)` en cada validación.

## Goals / Non-Goals

**Goals:**
- Verificar en cada PR las combinaciones de versiones que `composer.json` promete.
- Detectar errores de tipos antes de publicar, sin bloquear el trabajo por los errores ya existentes.
- Distribuir solo lo necesario.

**Non-Goals:**
- Publicar versiones automáticamente ni cambiar la distribución por repositorio `path` (hallazgo 13, segunda parte).
- Un umbral mínimo de cobertura: primero se mide.

## Decisions

### Matriz de CI

| Eje | Valores |
|---|---|
| PHP | 8.3, 8.4 |
| Laravel (vía Testbench) | 12 (`testbench ^10`), 13 (`testbench ^11`) |
| Dependencias | `--prefer-lowest`, `--prefer-stable` |

Se excluyen las combinaciones que Laravel 13 no soporte en PHP 8.3, si las hay. Pint y PHPStan corren
una sola vez, en la combinación más reciente. La cobertura se mide con `pcov` en esa misma combinación y
se publica como artefacto del job.

### PHPStan nivel 6 con baseline

Nivel 6 cubre tipos de parámetros y retornos sin exigir genéricos exhaustivos. Los errores actuales van
a `phpstan-baseline.neon`, y el CI falla solo por errores nuevos.

**Alternativa considerada:** nivel máximo sin baseline. Obligaría a corregir todo antes de tener CI.

### Qué se excluye del paquete

`export-ignore` para `/tests`, `/docs`, `/openspec`, `/.github`, `/.claude`, `phpunit.xml`, `phpstan*.neon`,
`pint.json` (si existe), `.gitattributes` y `.editorconfig`. **No** se excluye `src/Auth/Testing/`:
`TokenFactory` y sus claves forman parte de la API pública para las suites de las aplicaciones.

### Memoización de la clave por contenido

`JwtValidator` guarda en una propiedad estática el `InMemory` junto con el SHA-256 del PEM. Si cambia la
clave (rotación, `TokenFactory::fake()` en otro test, Octane), cambia la huella y se vuelve a parsear.

**Alternativa considerada:** guardarla en el contenedor como singleton. Rompería la regla de bindings
no singleton cuando dependen de la configuración.

## Risks / Trade-offs

- [`--prefer-lowest` falla] → Indica que un mínimo de `composer.json` es falso; se sube el mínimo en este
  cambio y se anota en el CHANGELOG.
- [La matriz alarga el CI] → Ocho combinaciones de la suite actual (~4 s cada una) más la instalación.
- [Memoización estática entre tests] → La huella por contenido evita usar una clave vieja; un test lo verifica.
