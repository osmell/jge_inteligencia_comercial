# ADR-002 — PostgreSQL en producción, SQLite en memoria en pruebas, SQL portable

- **Estado**: Aceptado · **Fecha**: 2026-10-18 · **Relacionado**: `README.md`, `docs/00_CONTEXTO_MAESTRO.md`

## Contexto
La base de producción es PostgreSQL en una VM dedicada. Las pruebas deben ser rápidas y no tocar datos de desarrollo.

## Decisión
Las pruebas usan **SQLite en memoria** (forzado en `phpunit.xml`); desarrollo y producción usan **PostgreSQL**. Todo SQL propio debe correr en ambos motores: se evitan funciones exclusivas de uno o se ramifican por `getDriverName()` (por ejemplo, formatos de fecha en `SalesQuery`). El orden de nulos se escribe explícito (`nulls last`).

## Consecuencias
- Un ciclo de pruebas completo dura menos de un minuto.
- Riesgo: algo que funcione en SQLite y falle en PostgreSQL (o al revés). Mitigación: la revisión manual en PostgreSQL de las pantallas con SQL propio y, en el futuro, un trabajo de CI contra PostgreSQL.
- Las restricciones que solo PostgreSQL impone (permisos, tipos estrictos) no se prueban en CI.

## Alternativas descartadas
PostgreSQL en las pruebas (más lento y más pesado de preparar en cada ejecución).
