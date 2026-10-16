# Fase 02 — Importación y calidad de datos

## Objetivo
Crear un marco reutilizable y auditable de importación Excel, comenzando por ventas.

## Columnas conocidas de la plantilla de ventas
`Documento`, `Serie`, `Código Cliente`, `Nombre Cliente`, `Código del Producto`, `Und.Vendidas`, `Nombre del Producto`, `Costo Tot.Vta.`, `Costo Unit.Vta.`, `Precio Unit.Vta.`, `Precio Unit.Normal`, `Total Neto`, `Total Impuesto`, `Venta + Imp`, `Gran Total`, `Nombre Vendedor`, `Nombre Nivel 2 Inv`, `Nombre Facturador`.

La muestra contiene múltiples líneas por documento. No se observan columnas explícitas de fecha, compañía o sucursal.

## Alcance
- Historial de importaciones, usuario, fecha, tipo, estado y resultado.
- Carga segura, vista previa, modo de validación y errores por fila/columna.
- Detección de duplicados e importación idempotente.
- Adaptador de ventas y registro de facturadores.
- Procesamiento en cola para archivos grandes.

## Reglas
- Una factura puede aparecer en múltiples filas; no contar cada línea como una factura.
- No asumir que `Documento` es único sin evaluar `Serie` y compañía.
- No tratar `Nombre Nivel 2 Inv` como línea/categoría sin confirmación.
- La muestra presenta diferencias entre `Venta + Imp` y `Gran Total`; reportarlas, no corregirlas silenciosamente.
- No convertir códigos a números si se pueden perder ceros iniciales.
- No borrar ni sobrescribir ventas históricas automáticamente.

## Entregables y aceptación
Adaptador de ventas, página de importaciones, reporte de errores y pruebas para encabezados incorrectos, filas inválidas y reimportación del mismo archivo sin duplicación silenciosa.
