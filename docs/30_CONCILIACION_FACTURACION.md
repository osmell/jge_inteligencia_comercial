# 30 · Conciliación de la facturación y corrección de equivalencias

Estado: **los 6 pasos hechos**. Queda abierta la diferencia de 0,34 % contra el origen (ver abajo).

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
2. **Hecho:** neto corregido por línea en `sales_line_corrections` (causa `portion`, regla versión 1: unidades entre 0 y 1, precio unitario ≥ 1 y neto > 0 → neto = precio unitario, impuesto con la tasa de la línea). Se calcula al importar ventas y con `php artisan sales:correct-net` (idempotente); los importes importados no se tocan. Sobre agosto da 9 050 306 contra 9 080 837 oficial (0,34 %). Las causas «varias porciones» y «precio por galón» no se pueden distinguir con los datos actuales.
3. **Hecho:** las facturas con alguna línea de serie `FA` en el detalle se registran en `sales_document_voids` (compañía + número, enlazadas a su venta si existe, ahora o cuando se cargue). En agosto las 188 con `FA` son exactamente las 188 que el archivo de ventas deja en cero. Al importar ventas, un documento anulado avisa (`document_voided`). Falta que los reportes dejen de contar esas ventas: se hace en el paso 4 junto con el neto corregido.
4. **Hecho:** todas las cifras de venta (Inicio, Ventas, Clientes, Productos, Reportes, Inteligencia, Metas y resumen semanal) usan el neto y el impuesto corregidos y dejan fuera las facturas anuladas. Todo pasa por `App\Services\Sales\SalesBasis` (unión con `sales_line_corrections` y exclusión de `sales_document_voids`); una consulta nueva de importes de venta debe usarlo. Con el parámetro `basis=imported` se ven los importes tal como se importaron; en la aplicación se elige con «Cifras de venta» en el encabezado (guardado por navegador, solo en pantallas anchas). Quedan en importe importado, a propósito: la conciliación del archivo contra lo guardado y los controles de integridad de documentos. El descuento y su porcentaje (Sub Total − Descuento) todavía no se corrigen. Las fechas de última compra y los estados de cliente siguen contando las facturas anuladas que tengan venta cargada.
5. **Hecho:** sección «Contra el sistema de origen» en Reportes ▸ Conciliación. Por compañía toma el último corte del resumen por vendedor hasta el fin del periodo y compara, por vendedor, la venta neta oficial contra la que calcula el sistema (corregida) y la importada, más las facturas (el origen cuenta también las anuladas). Estado: cuadra (hasta 1 córdoba), cerca (hasta 1 % de lo oficial) o diferencia (`origin_match_tolerance` y `origin_near_pct` en `config/reports.php`). Aparecen también los vendedores que solo existen en un lado. Cuenta como un problema de la conciliación mientras el total no cuadre.
6. **Hecho:** líneas de componente (catalizador, reductor, thinner: unidades > 0, precio unitario entre 0 y 1, costo > 0). Su costo se suma a la línea principal de la misma factura (la de mayor neto que no sea componente) y queda en cero en el componente, en `sales_line_component_costs` (asignación) y `sales_line_effective_costs` (costo vigente por línea, que es lo que une el margen); el margen de Ventas (por producto, categoría, vendedor…) usa ese costo en las cifras corregidas y el costo del archivo en «Como se importaron». En agosto: 3 032 componentes y 338 671 de costo reasignados a 1 321 líneas; 20 componentes sin línea principal conservan su costo. Se calcula al importar ventas y con `sales:correct-net`. Decisión: las unidades de los componentes se siguen contando (son consumo real y alimentan el inventario) y no hay un «precio promedio» calculado en ningún reporte que corregir.

## Diferencia abierta contra el origen (agosto de 2026)

Venta neta del origen (suma de la columna Venta del resumen) 9 080 836,59; sistema 9 050 306,09; faltan 30 530,49 (0,34 %). Hallazgos:

- La venta bruta y el descuento del origen se comportan igual: quedan 39 415 y 8 893 abajo, es decir, el faltante son ventas normales (descuento de ~13 %) y no un problema del descuento.
- Diez vendedores cuadran al centavo. En casi todos los demás la diferencia lleva exactamente 15 % de impuesto: son ventas gravadas que faltan o sobran.
- Las anuladas no explican nada (Fabio Matus tiene 128 mil en anuladas y cuadra exacto).
- Los saldos grandes se compensan entre vendedores (María Teresa −53 mil, Iris −26 mil frente a Stephany +30 mil, Jennifer +21 mil, Laysha +21 mil, Yefrín +13 mil), señal de documentos que el origen atribuye a otro vendedor; el faltante neto real queda en unos 30 mil.
- Para ubicar los documentos exactos hace falta el detalle consolidado de facturas del origen (una fila por factura con vendedor y venta neta).
