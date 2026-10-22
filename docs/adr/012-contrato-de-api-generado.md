# ADR-012 — Contrato de la API generado del código (OpenAPI) y tipos del frontend derivados

- **Estado**: Aceptado · **Fecha**: 2026-10-22 · **Relacionado**: `docs/27_CONTRATO_DE_API.md`

## Contexto
Los tipos de respuesta del frontend se escribían a mano en `src/lib/*.ts`, sin nada que avisara si el backend cambiaba una forma. Con un equipo más grande, esa deriva es una fuente segura de errores en producción.

## Decisión
- El backend publica su contrato como un documento **OpenAPI** generado por Scramble desde rutas, `FormRequest`, `Resource` y los arreglos que devuelven los servicios (`backend/openapi.json`, versionado).
- Los tipos de TypeScript se **generan** de ese documento (`openapi-typescript`) y los tipos de cada módulo del frontend se **derivan** de ellos.
- Tres comprobaciones automáticas impiden la deriva: `OpenApiSpecTest` (el documento coincide con el código), un paso de la CI (los tipos coinciden con el documento) y `tsc` (las pantallas coinciden con los tipos).
- Cada respuesta nueva usa un `Resource`; la cantidad de respuestas sin tipo solo puede bajar (`OPAQUE_BACKLOG`).
- La documentación navegable (`/docs/api`) es solo para administración.

## Consecuencias
- Un cambio de respuesta se ve en el diff de tres archivos y rompe la compilación de las pantallas afectadas, en lugar de fallar en producción.
- Un `Resource` hay que escribirlo con un arreglo literal para que el generador lo entienda (por eso `QuotationDetailResource` repite los campos del listado).
- Se agrega una dependencia en producción (`dedoc/scramble`), necesaria porque su configuración vive en `config/`.
- La migración de las 30 respuestas pendientes es gradual, módulo por módulo.

## Alternativas descartadas
Escribir el documento a mano (se desactualiza), un cliente generado para llamar a la API (cambia toda la capa `lib/api.ts` de una vez) y GraphQL (cambio de arquitectura sin necesidad).
