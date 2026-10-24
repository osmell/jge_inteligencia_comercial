# 29 · Reportes de evaluación

Reportes para evaluar el desempeño de **vendedores, operadores y sucursales**, y conciliar las cifras consolidadas con el detalle de facturación. Para supervisión, gerencia y administración (permiso `reports.view`; el vendedor no los ve). Plan de entrega por pasos: 1 base · 2 vendedores · 3 operadores · 4 sucursales · 5 conciliación · 6 menú desplegable «Reportes» · 7 salidas (imprimir, Excel, PDF y correo) · 8 cierre.

## Reglas comunes

- **Alcance por compañía, no por sucursal.** Quien tiene el permiso ve a TODOS los vendedores y operadores de cada compañía suya, con toda su venta, aunque tenga asignadas pocas sucursales. Nunca ve otra compañía: pedirla a mano no devuelve nada (y abrir el detalle de un vendedor ajeno da 404).
- **Toda cifra de venta es neta (sin IVA).** No hay margen en estos reportes: los archivos de venta no lo traen como dato propio (solo un costo unitario actual, no histórico).
- **Periodo y comparación.** Cualquier rango de hasta 3 años, comparado con el periodo anterior de igual duración, con las mismas fechas del año pasado o con un rango personalizado. Si el periodo de comparación dura distinto, el reporte lo avisa.
- **Semáforos.** Cada indicador evaluable lleva nivel *bien / atención / crítico* según `config/reports.php`. Los umbrales son valores iniciales por omisión, pendientes de validar con el cliente. Un indicador sin dato (por ejemplo, un vendedor sin meta) sale «sin dato», nunca cero ni verde.
- **Auditoría.** Abrir, exportar y enviar un reporte queda registrado (`report.viewed`, `report.exported`, `report.emailed`) con los filtros usados y, en los correos, los destinatarios.

## Reporte de vendedores (`GET /api/v1/reports/sellers`)

Una fila por vendedor activo o con ventas en el periodo. Con una sucursal elegida, solo los que vendieron en ella.

| Indicador | Definición | Semáforo |
|---|---|---|
| Venta neta, documentos, clientes, ticket promedio | Del periodo, por quien vendió (el vendedor del documento) | — |
| Crecimiento | (venta − venta del periodo de comparación) / comparación | `growth_pct` |
| Participación y puesto | % de la venta total y orden por venta neta | — |
| Cumplimiento de meta | Venta del periodo / meta del periodo. La meta de cada mes se prorratea por los días del vendedor (alta, baja y ausencias) dentro del periodo. **Si algún mes en que trabajó no tiene meta, no se califica.** Con una sucursal elegida tampoco se califica (la meta es de toda la compañía). Si el periodo incluye hoy y ya transcurrió ≥ 20 %, se proyecta el cierre. | `goal_attainment` (como en Metas: verde ≥ 100 %, rojo < 70 %) |
| Descuento | Descuento / subtotal, solo de líneas que informan subtotal | `discount_pct` |
| Ventas bajo precio normal | % de la venta (de líneas con precio normal) vendida con precio unitario menor al normal | `below_list_pct` |
| Concentración | % de su venta que sale de sus 3 mejores clientes | `top3_concentration_pct` |
| Categorías por cliente | Pares cliente-categoría distintos / clientes distintos | — |
| Cartera de clientes (**a hoy**) | Un cliente es de quien le facturó la última vez, si fue en los últimos 365 días. Estados (activo, nuevo, recuperado, inactivo, perdido) con las mismas reglas y plazos que Clientes. | `lost_customers_pct` (% de perdidos) |
| Cobranza (**a hoy**) | Saldo por cobrar de sus facturas y % vencido (emitidas hace más de 30 días: el sistema no guarda plazos por cliente) | `overdue_pct` |
| Cotizaciones | Emitidas en el periodo y % convertidas en venta | `quote_conversion_pct` |
| Contactos y acciones | Los registra el usuario vinculado al vendedor; sin usuario vinculado, «sin usuario» | — |
| Pendientes vencidos (**a hoy**) | Acciones de seguimiento sin atender cuya fecha ya pasó | `overdue_follow_ups` |

**Contraste.** Debajo de los totales se compara la venta consolidada (sin agrupar) con la suma de los vendedores; si no coinciden se muestra la diferencia. Al hacer clic en un vendedor se abre el **detalle de facturación**: cada documento del periodo (`GET /reports/sellers/{id}/documents`), paginado, cuyo total coincide con la fila del reporte.

**Pendiente para pasos posteriores:** promesas de pago cumplidas y tiempo de respuesta en WhatsApp por vendedor (requieren definir a quién se atribuye cada promesa y conversación).

## Reporte de operadores (`GET /api/v1/reports/operators`)

Quienes **facturan**. Una fila por operador activo o con facturación en el periodo (con una sucursal elegida, solo los que facturaron en ella). Mismo periodo, comparación, alcance por compañía, semáforos, contraste con lo consolidado y auditoría que el de vendedores.

| Indicador | Definición | Semáforo |
|---|---|---|
| Venta neta, documentos, ticket | Del periodo, por quien facturó; con su crecimiento (venta y documentos) contra la comparación | `growth_pct` |
| Líneas y líneas por documento | Cantidad de líneas facturadas | — |
| Días activos y documentos por día activo | Días con al menos una línea facturada | — |
| Descuento y ventas bajo precio normal | Igual que en vendedores | `discount_pct`, `below_list_pct` |
| Descuento alto | Líneas cuyo descuento es al menos el 20 % de su subtotal (`high_discount_line_pct`) | `high_discount_lines_pct` |
| Sin precio | Líneas con cantidad cuyo precio unitario o venta neta es cero (regaladas o mal capturadas). Las devoluciones (negativas) no cuentan. | `zero_price_lines_pct` |
| Documentos atípicos | Documentos cuya venta neta supera Q3 + 3 × (Q3 − Q1) de **todos** los documentos de su compañía en el periodo (cota de valores extremos de Tukey). Con menos de 20 documentos en la compañía no se evalúa: no hay base para decir qué es normal. | `atypical_documents_pct` |
| Documentos negativos | Venta neta menor que cero | — |
| Venta directa | % de su venta cuyo vendedor se llama igual que el operador (el facturador vendió y facturó) | — |

**Detalle del operador** (al hacer clic): sus facturas del periodo (`GET /reports/operators/{id}/documents`) y *a quién y cuándo factura* (`GET /reports/operators/{id}/distribution`): vendedores y sucursales con su porcentaje, y documentos por día de la semana y por hora. **La hora solo existe en los documentos cuyo archivo la traía**; si ninguno la trae, se dice en vez de mostrar una gráfica vacía.

**Lo que el sistema NO sabe y el reporte no inventa:** qué operador anuló o corrigió un documento (los archivos de venta no lo informan; las notas de crédito del sistema se registran por usuario, no por operador). El control de captura se basa solo en lo que traen los archivos.

Los parámetros (`high_discount_line_pct`, `atypical_min_documents`, `atypical_iqr_factor` y los umbrales de semáforo) están en `config/reports.php` y son valores iniciales pendientes de validar con el cliente.

## Reporte de sucursales (`GET /api/v1/reports/branches`)

Una fila por sucursal activa o con venta en el periodo. A diferencia de vendedores y operadores, **cada persona ve las sucursales que tiene asignadas** (una sucursal es una unidad del negocio, no una persona del equipo): el consolidado de contraste se limita a esas mismas sucursales para que coincida.

| Indicador | Definición | Semáforo |
|---|---|---|
| Venta neta, documentos, ticket, clientes | Del periodo, por sucursal, con crecimiento de venta y de documentos contra la comparación | `growth_pct` |
| Descuento y ventas bajo precio normal | Igual que en vendedores | `discount_pct`, `below_list_pct` |
| Concentración en clientes | % de la venta de la sucursal que sale de sus 3 mejores clientes | `top3_concentration_pct` |
| **Dependencia de una persona** | % de la venta de la sucursal que aporta el vendedor que más vende. **No cuenta a los vendedores «Tienda …»** (ventas de mostrador que el sistema crea por omisión): una sucursal que solo vende de mostrador no depende de nadie. | `top_seller_dependency_pct` |
| Cartera de clientes (**a hoy**) | Un cliente es de la sucursal que le facturó la última vez (últimos 365 días); mismos estados y plazos que Clientes | `lost_customers_pct` |
| Cobranza (**a hoy**) | Saldo por cobrar de las facturas de la sucursal y % vencido (emitidas hace más de 30 días) | `overdue_pct` |
| Existencias (**a hoy**) | Productos con existencia; **sin rotación** (con existencia y sin ventas en 60 días); **agotados con demanda** (vendidos en los últimos 90 días y hoy sin existencia); **faltantes abiertos** (ventas que superaron la existencia y siguen sin resolver) | `no_rotation_pct`, `stockout_demand_pct`, `open_shortfalls` |

**Solo cantidades de productos: ningún importe, costo ni valuación** (eso exige el permiso `inventory.costs`, que este reporte no otorga). Una sucursal sin inventario cargado queda «sin dato».

**Mezcla de categorías** (`GET /reports/branches/mix`): categorías en filas y sucursales en columnas; cada celda es el % de la venta de la sucursal que corresponde a esa categoría y su **índice** frente al conjunto (100 = misma proporción; 200 = el doble; 50 = la mitad; se marcan desde 150 hacia arriba y desde 50 hacia abajo). Se muestran las 15 categorías con más venta.

**Detalle de facturación** (`GET /reports/branches/{id}/documents`): los documentos de la sucursal en el periodo; su total coincide con la fila del reporte.

## Conciliación (`GET /api/v1/reports/conciliation`)

Contrasta la **venta consolidada** del periodo con sus partes y con otras fuentes, y señala dónde hay diferencias. Alcance por compañía (como vendedores y operadores); sin comparación con otro periodo. Todo es sobre lo guardado.

| Bloque | Qué compara | Cuándo hay diferencia |
|---|---|---|
| **Sumas por dimensión** | Venta neta y documentos del consolidado contra la suma de las filas por compañía, sucursal, vendedor y operador | La suma no coincide (diferencia > 0.01 o de documentos) |
| **Integridad de los documentos** | Que cada documento cuadre con sus líneas: número de líneas declarado, documentos sin líneas, líneas de otra compañía, sucursal o fecha, y líneas donde neto + impuesto no suman su total (tolerancia 0.01) | Cualquier documento con el problema; se abre la lista con la descripción de cada uno |
| **Archivos de ventas importados** | Lo que declaraba cada archivo (documentos nuevos y venta neta) contra lo que hoy hay guardado de ese lote | Los documentos guardados no son los declarados, o la venta neta difiere. **Si el archivo traía documentos ya cargados (duplicados), la venta neta no se compara** (su venta está en el total del archivo pero no en el lote): solo se compara la cantidad de documentos nuevos. Los lotes que incluyen compañías fuera del alcance de la persona no se evalúan. |
| **Facturas de crédito contra ventas** | Cada factura de crédito enlazada a una venta: mismo cliente, mismo neto (subtotal − descuento) y mismo impuesto, con la tolerancia de las importaciones (`IMPORT_AMOUNT_TOLERANCE`) | Cliente, neto o impuesto distintos. Las no enlazadas se cuentan aparte: aún no hay venta que comparar. |

El **detalle de facturación** de cualquier vendedor, operador o sucursal se abre en la misma pantalla (selector «Ver por»), con los documentos del periodo y su total para contrastarlo.

`GET /reports/conciliation/issues?code=…` lista, paginado, los documentos (o facturas de crédito) con una diferencia concreta. Cada consulta queda en Auditoría.

**Límite:** el contraste entre tablas del propio sistema detecta datos corruptos o importaciones perdidas, pero **no** puede saber si el archivo de origen (el del sistema de facturación) estaba bien; para eso hay que comparar contra el total que da ese sistema.
