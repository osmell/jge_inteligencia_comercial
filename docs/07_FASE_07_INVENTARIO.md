# Fase 07 — Inventario, movimientos y equivalencias

## Objetivo
Controlar existencias por compañía, sucursal y producto, enlazando entradas, ventas y otros movimientos.

## Alcance
- Catálogo de productos, líneas, categorías y unidades.
- Importación de entradas mediante plantilla futura.
- Carga y conciliación del inventario inicial.
- Salidas a partir de líneas de venta.
- Devoluciones, traslados y ajustes autorizados.
- Equivalencias específicas por producto.
- Existencias y reportes de rotación.

## Reglas
- Convertir cantidades a una unidad base definida por producto.
- Cada equivalencia tiene unidad origen/destino y factor.
- No asumir contenido universal de cajas/paquetes.
- Evitar doble salida al reimportar ventas.
- No calcular existencias desde movimientos parciales sin saldo inicial conciliado.
- Ajustes con motivo y auditoría; devoluciones revierten movimientos sin borrar historia.

## Aceptación
Existencias reconciliables por producto/sucursal/unidad; equivalencias comprobables; reimportaciones sin movimientos duplicados.

## Preguntas abiertas
Ver `15_PREGUNTAS_FASE_07_INVENTARIO.md` (decisiones #11, #14 y #15). Las respuestas definen las plantillas y la fecha de corte.

## Pasos propuestos
Los pasos 1 y 2 no dependen de las plantillas y pueden avanzar ya; del 3 en adelante necesitan las respuestas.

1. **Unidades, equivalencias y movimientos (sin plantillas).** Unidad base por producto; equivalencias con factor por producto (origen, destino, factor exacto) y conversión a la unidad base con aritmética entera; libro de movimientos inmutable (entrada, salida, traslado, ajuste, reversa enlazada) con clave de origen única para que reimportar no duplique. Permiso nuevo `inventory.view`.
2. **Consulta de existencias y kárdex.** Existencia por compañía, sucursal y producto (suma de movimientos desde la fecha de corte), kárdex del producto con saldo corrido y filtros; las existencias solo se muestran donde hay inventario inicial conciliado.
3. **Importaciones: inventario inicial y catálogo de unidades/equivalencias.** Plantillas con validación, vista previa y conciliación (dos tipos nuevos de importación) y fecha de corte por sucursal.
4. **Importación de entradas.** Plantilla de entradas con costo, idempotente por documento y serie.
5. **Salidas desde ventas.** Cada línea de venta genera su salida de forma idempotente (también al importar ventas anteriores a la fecha de corte: se ignoran), con aviso de saldos negativos para conciliar.
6. **Devoluciones, traslados y ajustes.** Captura en pantalla con motivo, autorización y auditoría; reversas sin borrar historia.
7. **Reportes de rotación y cobertura.** Rotación, cobertura en días, productos sin movimiento y mínimos/máximos con alertas, según las respuestas.

## Respuestas recibidas (responsable)
- **Unidad de referencia: galón.** Hay equivalencias desde barril, cubeta, litro, octavo, 1/16, 1/32 y 1/64. Habrá otras unidades sin equivalencia (cada producto conserva su propia unidad base).
- **Inventario inicial** (Excel): Fecha Corte, Código Producto, Unidad de Medida, Cantidad, Sucursal, Compañía.
- **Entradas** (Excel): Fecha, Documento, Proveedor, Sucursal, Compañía, Código Producto, Unidad Medida, Costo, Descuento, Sub Total, Iva, Total, Observaciones. *(No trae columna de cantidad: pendiente.)*
- **No se permiten existencias negativas.** Valuación por **costo promedio ponderado**. **Los costos los ve el administrador.**
- Rotación, máximos y mínimos: los propone el sistema (ver propuesta).
- No hay inventario real: se hará una **toma de prueba** (datos de demostración) para validar y presentar la demo.
