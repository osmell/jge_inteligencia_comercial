# 29 · Reportes de evaluación

Reportes para evaluar el desempeño de **vendedores, operadores y sucursales**, y conciliar las cifras consolidadas con el detalle de facturación. Para supervisión, gerencia y administración (permiso `reports.view`; el vendedor no los ve). Plan de entrega por pasos: 1 base · 2 vendedores · 3 operadores · 4 sucursales · 5 conciliación · 6 menú desplegable «Reportes» · 7 salidas (imprimir, Excel, PDF y correo) · 8 cierre.

## Menú

Los reportes están en un menú desplegable **«Reportes»** (Vendedores, Operadores, Sucursales, Productos y Conciliación; todo el menú está organizado en grupos desplegables: General, Administración, Catálogos, Inteligencia, Reportes y Datos, y solo General empieza abierto). Empieza cerrado, se abre con el botón (con teclado y lector de pantalla: `aria-expanded`), se abre solo al entrar a cualquiera de sus pantallas y recuerda en el navegador si se dejó abierto. Solo aparece para quien tiene el permiso `reports.view`. La barra de comandos (tecla `/`) los encuentra por su nombre completo («Reporte de vendedores»). Los cuatro comparten la misma barra de periodo, comparación, compañía y sucursal.

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

## Mezcla de productos (`GET /api/v1/reports/products`)

Responde a «¿el vendedor y el operador ofrecen todo el catálogo, o qué línea, categoría o producto es lo que más se mueve?». Menú **Reportes → Productos** (`/reportes/productos`). Se elige **a quién se evalúa** (`subject`: vendedores u operadores) y **por qué nivel** se ve la venta (`level`: producto, categoría —por omisión—, línea o grupo; son los niveles 3, 2 y 1 del catálogo de productos).

- **Lo más facturado**: ranking de mayor a menor venta neta con peso en el total, cambio frente a la comparación, unidades, documentos y clientes distintos. A nivel producto se listan los 200 principales y se indica cuántos hay en total; los otros niveles se listan completos.
- **Amplitud de catálogo por persona**: venta y peso; productos distintos; **cobertura** (categorías y líneas del catálogo de su compañía con venta neta positiva, como «3 de 4» y porcentaje); grupos tocados; y **dependencia** (porcentaje de su venta que sale de sus 5 productos principales: más alto = repite lo mismo).
- **Detalle de una persona** (`GET /reports/products/sellers/{id}` y `/operators/{id}`): su ranking con el **índice de mezcla** frente a su equipo (100 = pesa igual; ▲ 150 o más, ▼ 50 o menos), los **huecos** (categorías y líneas con venta en el equipo y ninguna en esa persona, de mayor a menor, hasta 15 de cada una; se excluye «Sin clasificar») y cuántos de los **20 productos estrella** del equipo no vendió. El equipo son sus compañeros del mismo tipo en la misma compañía.
- **Qué cuenta como vendido**: venta neta positiva en el periodo; una devolución o nota de crédito no cuenta como haber vendido esa categoría.
- **Lo que no sabe**: el sistema solo ve lo facturado. «No vendió» no prueba que no lo haya ofrecido (el reporte lo dice en pantalla y en las salidas). Las cotizaciones podrían sumar una señal de «cotizó pero no cerró»: queda para una segunda etapa si el cliente la quiere.
- **Catálogo**: los productos no tienen estado activo/inactivo, así que la cobertura se mide contra todas las categorías y líneas del catálogo de la compañía, incluidas las de productos que ya no se manejen.
- Sin costos, margen ni valuación. Mismo permiso (`reports.view`), alcance por compañía y auditoría (`report.viewed`, `report.exported`, `report.emailed`) que los demás.
- Salidas: imprimir, Excel y correo traen todas las filas; **el PDF muestra como máximo 100 filas por tabla** (`reports.pdf_max_rows`) y lo avisa en la propia tabla, porque Dompdf agota los 128 MB de memoria de PHP con unas 300 filas.

## Salidas: imprimir, Excel, PDF y correo

Los cuatro reportes tienen la misma barra de salidas, sobre el **mismo periodo y filtros** de la pantalla (permiso `reports.view`, mismo alcance):

- **Imprimir**: hoja apaisada sin el menú ni los controles, con quién la imprimió y cuándo. Las tablas se despliegan completas.
- **Excel** (`GET /reports/{reporte}/export?format=xlsx`): hoja «Resumen» (periodo, comparación, filtros, avisos y cifras clave) y una hoja por tabla. **Las cifras salen como números con formato** para poder sumarlas; cada indicador con semáforo lleva al lado su nivel escrito («Bien», «Atención», «Crítico», «Sin dato»). El texto que viene de los archivos importados y parece fórmula se neutraliza.
- **PDF** (`format=pdf`): lo mismo, apaisado. Una tabla ancha se **parte en bloques de columnas** que repiten el nombre, para que quepa. Los niveles van escritos, no solo en color.
- **Correo** (`POST /reports/{reporte}/email`): ver abajo.

Los reportes son `sellers`, `operators`, `branches` (incluye la mezcla de categorías) y `conciliation`. Se exporta el **resumen que se ve en pantalla**; el detalle de facturas de una persona o sucursal no va dentro del archivo (se consulta en pantalla).

### Correo con adjunto

Es lo único irreversible de los reportes: las cifras salen de la empresa. Reglas:

1. **Direcciones escritas a mano** (hasta 10 por envío). Se revisan una por una y hay que **confirmar expresamente** que esas personas pueden recibir las cifras; la API tampoco envía sin `confirmed`.
2. **Un correo por destinatario**, con el archivo **adjunto** (nunca un enlace); nadie ve a los demás destinatarios.
3. **Tope por persona y hora** (`reports.email_per_hour`, 30 destinatarios; cada uno cuenta). Sin cupo suficiente no se envía nada.
4. **Auditoría** (`report.emailed`): quién, qué reporte y formato, con qué filtros, **a qué direcciones** y el resultado de cada envío. Cada correo queda además en el registro de correos (`Correo` en administración).
5. Si el correo del sistema **no está configurado, no se intenta por otra vía** y no se gasta cupo. Un envío fallido se reporta como fallido en el momento; no se reintenta después sin que nadie lo sepa.

## Verificación y rendimiento

- **Pruebas**: cada indicador se comprueba contra ventas, cartera, crédito, inventario y seguimiento declarados a mano (más de 150 pruebas del apartado), además de las matrices de acceso, sesión revocada y clave temporal que recorren todas las rutas.
- **PostgreSQL**: todas las pruebas del sistema (930) pasan **también en PostgreSQL** (`composer test:pgsql`, y como trabajo propio en la CI), no solo en SQLite.
- **Mezcla de productos** (PostgreSQL, 25 000 documentos, 150 000 líneas y 6 000 productos en 299 días): el reporte responde en 1,3–1,8 s con el año a la fecha y 0,3 s con un mes; el detalle de una persona, en 1,3–1,4 s; Excel en menos de 0,1 s y PDF en 0,8 s. Sin índices nuevos. El PDF sin recorte agotaba la memoria con ~300 filas; con el tope de 100 filas usa unos 35 MB.
- **Rendimiento** (PostgreSQL, 25 000 documentos y 150 000 líneas en un año, sin índices nuevos): cada reporte responde en 0,2–1,3 s (el de un mes, la mitad); el detalle de facturas y de a quién factura un operador, en menos de 0,1 s; Excel y PDF tardan 1,4–2,9 s. Con mucho más volumen conviene volver a medir.
- **Seguridad**: ver `docs/28_SEGURIDAD.md` (revisión del apartado de Reportes) y `docs/adr/015-reportes-de-evaluacion.md`.

## Decisiones pendientes de validar con el cliente

1. **Umbrales de los semáforos** (`config/reports.php`): son valores iniciales.
2. **Definición de «vencido»**: facturas emitidas hace más de 30 días (el sistema no guarda plazos de crédito por cliente).
3. **Alcance de un supervisor limitado a una sucursal**: ve a todo el equipo de su compañía en vendedores, operadores y conciliación, pero solo sus sucursales en el reporte de sucursales.
4. **Anulaciones por operador**: el sistema no sabe quién anuló o corrigió un documento; si el cliente lo necesita, hay que decidir cómo registrarlo.
5. **Promesas de pago cumplidas y tiempo de respuesta en WhatsApp por vendedor**: aún no están en el reporte de vendedores (falta decidir a quién se atribuye cada una).
6. **Mezcla de productos**: (a) la cobertura se mide contra todo el catálogo; si el cliente retira productos conviene un estado activo/inactivo; (b) incluir cotizaciones («cotizó pero no cerró») como señal de lo ofrecido; (c) umbrales de semáforo para cobertura y dependencia (hoy se muestran sin semáforo); (d) si el PDF debe traer más de 100 filas por tabla hay que aumentar la memoria de PHP.
7. **Correo a direcciones libres**: decisión del cliente; si se quisiera más control, una lista de dominios permitidos o aprobación de un administrador para direcciones nuevas.
