# ADR-010 — Dashboard por secciones enchufables

- **Estado**: Aceptado · **Fecha**: 2026-10-21 · **Relacionado**: `docs/18_FASE_10_DASHBOARD_INICIAL.md`

## Contexto
El dashboard de inicio crecía con cada fase (ventas, metas, cartera, cotizaciones, seguimiento, conversaciones…) y `DashboardService` llegó a 387 líneas con una sección por método y un `match` central.

## Decisión
Cada sección es una clase que extiende `DashboardSection` (clave, permiso, `availableFor`, `build`) en `App\Services\Dashboard`. Se registran, en el orden de pantalla, con una etiqueta del contenedor en `AppServiceProvider`. `DashboardService` solo resuelve perfil, periodo y filtros, y delega.

## Consecuencias
- Agregar una sección es crear una clase y añadirla a la lista; ninguna otra se toca (abierto/cerrado).
- Cada sección se calcula por separado, con el mismo permiso y alcance que su pantalla de origen.
- El orden en pantalla depende del orden de la lista; el frontend sigue decidiendo cuáles muestra por perfil.

## Alternativas descartadas
Mantener el `match` central y un servicio por sección sin interfaz común.
