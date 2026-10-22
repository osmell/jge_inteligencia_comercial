# ADR-004 — Importaciones como tipos enchufables con validación en cola

- **Estado**: Aceptado · **Fecha**: 2026-10-18 · **Relacionado**: `docs/02_FASE_02_IMPORTACION_CALIDAD_DATOS.md`

## Contexto
Hay siete cargas desde Excel (ventas, clientes, productos, crédito, inventario…) con archivos de hasta 200 000 filas. Todas comparten el flujo subir → validar → revisar errores → confirmar, y solo difieren en columnas, reglas y escritura.

## Decisión
Un **marco común** (lector en streaming, mapeo de encabezados, `ErrorCollector`, agrupación, `ImportRunner`) y una interfaz `ImportType` con una implementación por carga, registradas en `config/imports.php`. La validación y la importación corren **en cola** (`queue:work`); la escritura es **transaccional**: o entra todo el lote o no entra nada. Las reglas repetidas viven en `Imports/Rules`.

## Consecuencias
- Agregar una carga nueva es agregar una clase y registrarla; el motor no se toca.
- Hace falta un worker corriendo; un lote atascado se marca fallido con `imports:fail-stale`.
- Los archivos se guardan en disco privado y no se ejecutan ni se interpretan fuera del lector.

## Alternativas descartadas
Importar de forma síncrona en la petición (se agota el tiempo con archivos grandes) y un controlador por carga (duplicaba el flujo).
