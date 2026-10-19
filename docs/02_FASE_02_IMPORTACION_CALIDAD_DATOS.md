# Fase 02 — Importación y calidad de datos

## Objetivo
Crear un marco reutilizable y auditable de importación Excel, comenzando por ventas.

## Columnas conocidas de la plantilla de ventas
`Documento`, `Serie`, `Código Cliente`, `Nombre Cliente`, `Código del Producto`, `Und.Vendidas`, `Nombre del Producto`, `Costo Tot.Vta.`, `Costo Unit.Vta.`, `Precio Unit.Vta.`, `Precio Unit.Normal`, `Total Neto`, `Total Impuesto`, `Venta + Imp`, `Gran Total`, `Nombre Vendedor`, `Nombre Nivel 2 Inv` (categoría), `Nombre Operador` (antes `Nombre Facturador`; se aceptan ambos).

La muestra contiene múltiples líneas por documento. El responsable del Excel confirmó que se agregarán columnas de **fecha, compañía y sucursal**; sus nombres y formatos exactos siguen pendientes (ver más abajo).

## Alcance
- Historial de importaciones, usuario, fecha, tipo, estado y resultado.
- Carga segura, vista previa, modo de validación y errores por fila/columna.
- Detección de duplicados e importación idempotente.
- Adaptador de ventas y registro de facturadores.
- Procesamiento en cola para archivos grandes.

## Reglas
- Una factura puede aparecer en múltiples filas; no contar cada línea como una factura.
- No asumir que `Documento` es único sin evaluar `Serie` y compañía.
- `Nombre Nivel 2 Inv` es la **categoría** (confirmado); el Nivel 1 es la línea.
- `Venta + Imp` y `Gran Total` deben ser iguales a `Total Neto + Total Impuesto` (confirmado); toda diferencia se reporta como error, nunca se corrige en silencio.
- No convertir códigos a números si se pueden perder ceros iniciales.
- No borrar ni sobrescribir ventas históricas automáticamente.

## Entregables y aceptación
Adaptador de ventas, página de importaciones, reporte de errores y pruebas para encabezados incorrectos, filas inválidas y reimportación del mismo archivo sin duplicación silenciosa.

## Estado: completada (pasos 1 a 7)
Implementado en `backend/app/Imports` (marco y adaptador de ventas), `backend/app/Jobs` (validar y confirmar en cola), `ImportController` (API) y las pantallas **Importaciones**. Verificado con `Phase2AcceptanceTest` (una prueba por regla) y de extremo a extremo en navegador.

| Regla / criterio | Cómo se cumple |
|---|---|
| Encabezados incorrectos | Se rechaza el archivo y se nombra cada columna obligatoria que falta. |
| Filas inválidas | Error por fila y columna, con todos los errores de la fila; descarga en CSV. |
| Sin importación parcial silenciosa | Todo o nada: con un solo error no se puede confirmar; la escritura es una transacción. |
| Reimportar el mismo archivo | Rechazado por hash (`already_imported`). Documentos repetidos en otro archivo: se informan como «ya importados» y no se duplican. |
| Conflictos | Un documento existente con contenido distinto se rechaza (`document_conflict`); nunca se sobrescribe. |
| Una factura en varias filas | Se agrupa por compañía + serie + documento. |
| Códigos | Siempre texto; se avisa si Excel los entrega como número. |
| Importes | Conciliación `Total Neto + Total Impuesto = Venta + Imp = Gran Total`; se guardan tal como vienen. |
| Modo validación y vista previa | Subir solo valida; se revisa resumen, vista previa y errores antes de confirmar. |
| Trazabilidad | Hash del archivo, usuario, fechas, resultado y errores por lote; cada venta apunta a su lote; auditoría de subir, confirmar, descartar y completar. |
| Cola | Validación e importación corren en el worker; `imports:fail-stale` marca como fallidos los lotes atascados. |
| Ventas históricas | `SalesDocument` y `SalesLine` son inmutables desde la aplicación. |

### Pendiente de confirmar con el responsable del Excel (se cambia en `backend/config/imports.php`, sin tocar código)
- Nombres exactos de las columnas de **fecha, compañía y sucursal** (hoy `Fecha`, `Compañía`, `Sucursal`) y si compañía/sucursal vienen como código o nombre.
- **Formato de fecha** en texto (hoy `Y-m-d` y `d/m/Y`; `d/m/Y` es ambiguo con `m/d/Y`) y si la fecha trae hora.
- **Tasa de IVA** (`sales.tax_rates`; vacío = no se valida la tasa).
- **Tolerancia de redondeo** de importes (`amount_tolerance`, hoy 0.01).
- Si `Costo Tot.Vta.` = unidades × costo unitario (hoy solo advertencia).
- Si los importes son por línea o por documento (los controles actuales valen en ambos casos).

### Límites y operación
- Máximo 20 MB y 200 000 filas de datos por archivo (`IMPORT_MAX_FILE_KB`, `IMPORT_MAX_ROWS`).
- **Rendimiento medido** (PostgreSQL real, worker de Redis, peor caso: 200 000 líneas, un documento por línea, archivo de 15 MB): validar 92 s con 305 MB de pico; importar 247 s con 367 MB de pico. Con 20 000 líneas: validar 9 s e importar 25 s con 77 MB, que cabe incluso en el límite por defecto de PHP (128 MB). Un archivo diario típico tarda segundos.
- La importación relee el archivo en streaming y escribe documentos y líneas por bloques; no guarda las líneas en memoria.
- El worker corre con 1 GB de memoria (`docker-compose.yml`); el programador (`schedule:work` o cron) debe ejecutarse para `imports:fail-stale`.
- Los archivos subidos se conservan hasta que el lote se descarte; la política de retención y respaldo es la decisión #11 pendiente.
