# ADR-011 — Calidad automatizada: PHPStan nivel 8, `strict_types` y reglas de arquitectura

- **Estado**: Aceptado · **Fecha**: 2026-10-21 · **Relacionado**: `docs/24_ANALISIS_CODIGO_Y_GUIA_DESARROLLO.md`, `CONTRIBUTING.md`

## Contexto
Un equipo nuevo necesita que el código se proteja solo: tipos que no se conviertan en silencio, nulos manejados y límites de diseño que no se erosionen.

## Decisión
- `declare(strict_types=1)` en todo archivo PHP (regla de Pint).
- **PHPStan/Larastan nivel 8** sin *baseline*. Excepciones documentadas en `phpstan.neon`: SQL dinámico con fragmentos de listas blancas (4 archivos) y `$request->user()` no nulo en controladores y peticiones.
- `tests/Unit/ArchitectureTest` convierte reglas de diseño en pruebas: sin `DB::` ni `app()` en controladores, sin `Http::` fuera de `app/Integrations`, sin comparar roles en controladores, ninguna clase de más de 400 líneas.
- La **CI** (GitHub Actions) ejecuta Pint, PHPStan, pruebas, `tsc`, lint, Vitest y build en cada PR.

## Consecuencias
- Los errores de tipos y nulos se detectan antes de llegar a producción; el costo es escribir tipos de arreglos en los comentarios.
- Subir de nivel o agregar una excepción es una decisión de revisión de código, no un parche.
- Las reglas de arquitectura rompen la compilación: se prefiere dividir la clase antes que subir el límite.

## Alternativas descartadas
Un *baseline* de errores heredados (esconde la deuda) y revisar solo a mano (no escala con el equipo).
