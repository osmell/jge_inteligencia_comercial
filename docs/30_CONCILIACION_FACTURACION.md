# 30 · Conciliación de la facturación y corrección de equivalencias

Estado: **paso 1 de 6 hecho** (carga de los dos archivos de referencia). Los pasos 2 a 6 están pendientes.

## El problema

En el archivo de ventas que se importa (`Ventas x Productos`), cuando `Und.Vendidas` es menor que 1 la línea se vendió por
**equivalencia** (1/4 de galón, 1/8…). `Precio Unit.Normal` es el precio real de esa porción (con impuesto), pero el reporte del sistema
de origen multiplica otra vez por la fracción al calcular `Total Neto`; por eso el neto de esas líneas queda subestimado.

Análisis de agosto de 2026 (archivos en `docs/`, no versionados):

- El «Detalle de Facturación» coincide línea por línea con el archivo de ventas (misma Venta Total que Total Neto): no corrige por sí solo.
- Las 188 facturas «faltantes» del archivo de ventas son **anuladas**: el detalle trae su original (serie `F$`) y su copia de anulación (serie `FA`),
  y el archivo de ventas las deja en cero. Sin ellas el detalle suma lo mismo que lo importado (7 612 055).
- Regla probada: en líneas con unidades menores a 1 y venta mayor a 0, neto corregido = Venta Total ÷ Unidades. Da 9 050 333 contra 9 080 837 de
  Venta Neta oficial del ERP (diferencia de 0,34 %); 10 vendedores cuadran exacto. Los residuos están en pocos vendedores
  (María Teresa Baltodano −53 mil, Iris Calero −26 mil, Stephany López +30 mil, Jennifer Pineda +21 mil, Laysha Herrera +21 mil, Yefrín Díaz +13 mil).
- El resumen por vendedor trae la «Venta Neta» en blanco en una fila (TIENDA IVAN): se toma Venta − Devoluciones.

## Archivos (se cargan por día acumulado: del primer día del período al día de corte)

| Archivo | Tipo de importación | Qué aporta |
|---|---|---|
| Ventas x Productos (el de siempre) | `sales` | Ventas, vendedor y operador. Sin cambios. |
| Detalle de Facturación | `billing_detail` | Precio establecido en la factura por línea; detecta anuladas (serie `FA`). |
| Ventas x Vendedor (resumen) | `seller_summary` | Venta Neta oficial por vendedor, para conciliar. |

Los dos archivos nuevos no traen compañía: se agrega la columna **Compañía** (como en ventas). El resumen no trae fecha: se agrega **Fecha Corte**
(y **Fecha Desde** si el período no empieza el día 1). Los archivos deben estar guardados como `.xlsx`.

Reglas de carga:

- Detalle: una factura = compañía + `Docu. No.` (la serie no cuenta). Un documento idéntico al ya cargado se omite; uno que cambió (p. ej., anulado después)
  **reemplaza** su detalle anterior, con aviso. Las líneas repetidas se conservan y se avisan (no se sabe si son legítimas).
- Resumen: un archivo del mismo corte reemplaza al anterior por vendedor. Totales descuadrados (Venta ≠ Bruta − Descuento, Total ≠ Neta + Impuesto − Retención) solo avisan.
- Ninguno modifica las ventas importadas.

## Plan

1. **Hecho:** importar el detalle y el resumen, acumulativos por día, sin duplicar.
2. Neto corregido por línea con la causa (porción única, varias porciones, precio por galón); los importes importados no se tocan.
3. Marcar las facturas anuladas (con fila `FA`) para que no sumen.
4. Reportes con el neto corregido y un interruptor para ver el importado.
5. Conciliación contra el resumen por vendedor, con las diferencias.
6. Líneas de componentes (catalizador, reductor, thinner; precio simbólico 0,01): costo reasignado a la línea principal y fuera de precio promedio y conteo de unidades.
