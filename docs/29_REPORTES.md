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
