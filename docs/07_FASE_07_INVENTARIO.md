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
