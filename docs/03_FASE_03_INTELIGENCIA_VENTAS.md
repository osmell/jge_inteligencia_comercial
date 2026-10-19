# Fase 03 — Inteligencia de ventas

## Objetivo
Presentar estadísticas por compañía, sucursal, vendedor, facturador, cliente, producto y periodo.

## Bloqueantes
Resolver cómo se obtiene la fecha de venta y cómo se asigna compañía/sucursal. No inferirlos por nombre de vendedor. Confirmar qué campo de importe es oficial.

## Alcance
- Dashboard ejecutivo y filtros.
- Venta neta, impuestos, total, documentos únicos, unidades, clientes compradores y ticket promedio.
- Ventas por compañía/sucursal/vendedor/facturador.
- Comparación de periodos y metas mensuales por vendedor.
- Rankings de producto por unidades, importe, frecuencia y margen cuando el costo sea válido.
- Exportación de reportes.

## KPI de facturadores
La plantilla incluye `Nombre Facturador`, pero no fecha/hora de emisión ni errores. Inicialmente se pueden medir documentos únicos e importes asociados por facturador, cuando se resuelva la fecha. No afirmar productividad, velocidad o tasa de error sin datos de tiempos/eventos.

## Reglas
- Separar neto, impuesto y total.
- No contar cada línea como factura.
- Margen bruto solo con costo histórico fiable y fórmula acordada.
- Comparar periodos equivalentes y advertir cuando el historial sea incompleto.

## Aceptación
Los totales se concilian con un reporte validado; los filtros respetan permisos; las facturas con varias líneas cuentan una sola vez en KPI de documentos.

## Avance

### Paso 2 — API de agregaciones y KPI (implementado)
Permiso `sales.view` («Inteligencia de ventas»): lo tiene el Administrador; el resto de roles se concede desde «Roles y permisos». Los datos se limitan al ámbito de compañía/sucursal del usuario (global scope de `SalesLine`); pedir otra compañía por parámetro devuelve ceros, no error ni datos.

| Endpoint | Qué devuelve |
|---|---|
| `GET /api/v1/sales/summary` | KPI del periodo y del periodo equivalente anterior (misma duración, inmediatamente antes), con variación porcentual y avisos |
| `GET /api/v1/sales/breakdown?dimension=…` | Mismos KPI por grupo. Dimensiones: `company`, `branch`, `seller`, `operator`, `category`, `product`, `customer`, `month`, `day` |

Parámetros comunes: `from` y `to` (obligatorios, `YYYY-MM-DD`, máximo 1 096 días) y filtros opcionales `company_id`, `branch_id`, `seller_id`, `operator_id`, `category`, `product_code`, `customer_code`. El desglose admite además `sort` (`net_total`, `tax_total`, `grand_total`, `units`, `documents`, `customers`, `margin`) y `limit` (1–200, por defecto 20; `meta.truncated` indica recorte). `month` y `day` van en orden cronológico y sin límite.

Reglas aplicadas:
- Venta neta, impuesto y total (`Gran Total`) se suman por separado, tal como vienen en el archivo; no se recalculan.
- **Documentos únicos:** `count(distinct documento)`. Una factura de varias líneas cuenta una vez *dentro de cada grupo*; en categoría o producto la misma factura puede aparecer en varios grupos, por lo que esas cifras no se suman entre filas (`meta.notes.documents`).
- Ticket promedio = venta neta ÷ documentos únicos. Clientes compradores = códigos de cliente distintos.
- **Margen estimado** (`estimated_margin`, `estimated_margin_pct`): solo sobre líneas con costo válido (> 0) y con el costo **actual**, no el histórico (`notes.margin = estimated_with_current_cost`). `cost_coverage_pct` indica qué parte de la venta neta tiene costo.
- Advertencia `previous_period_incomplete`: la primera venta cargada es posterior al inicio del periodo previo, así que la comparación es parcial. Si no hay base de comparación, la variación es `null` (no se inventa un 0 %).
- Importes como texto con 2 decimales; las sumas se hacen en el motor de base de datos y se redondean al presentar.

Pendiente de los bloqueantes: las cifras asumen importes **por línea** (como los guarda el modelo actual). Si el responsable del Excel confirma que se repiten por documento, hay que ajustar la importación, no esta API.

Verificado con PostgreSQL sobre los datos de demostración: los totales coinciden con una consulta SQL directa (30 días: 597 documentos, 579 061,99 de venta neta).

### Paso 3 — Dashboard de ventas (implementado)
Pantalla `/ventas` (menú «Inteligencia → Ventas», ruta protegida por `sales.view`). Sin librerías nuevas: los gráficos son SVG propios (`components/charts.tsx`).

- **Filtros:** periodo (este mes, mes anterior, últimos 30/90 días, año a la fecha o personalizado), compañía y sucursal (la sucursal depende de la compañía). Al elegir un nombre en la tabla de desglose se filtra todo el tablero (vendedor, operador, categoría, producto o cliente) y el filtro aparece como etiqueta que se puede quitar. Un rango inválido no consulta y explica el motivo junto al campo.
- **Tarjetas:** venta neta, impuesto, total facturado, documentos, clientes compradores, ticket promedio, unidades y margen estimado, cada una con su variación contra el periodo anterior (icono + signo + texto, nunca solo color; «Sin base de comparación» si no hay dato).
- **Tendencia:** venta neta por día (rangos de hasta 92 días) o por mes, con alternativa en tabla («Ver datos en tabla»).
- **Desglose:** pestañas por compañía, sucursal, vendedor, operador, categoría, producto y cliente (15 filas con más venta neta).
- **Avisos visibles:** historial incompleto del periodo anterior; margen con costo actual y cobertura de costo; recorte de filas; facturas repetidas entre categorías o productos.

Verificado: typecheck, lint, 37 pruebas (9 nuevas) y build. **Pendiente:** revisión visual en navegador y de la vista móvil; no se pudo hacer en la sesión de desarrollo.

### Paso 4 — Exportación de reportes (implementado)
`GET /api/v1/sales/export?format=csv|xlsx` con el mismo periodo y filtros del dashboard (permiso `sales.view`). En la pantalla: botones «Exportar a Excel» y «CSV por <pestaña activa>» (se desactivan con un rango inválido).

- **Excel (`ventas-<desde>_<hasta>.xlsx`):** hoja «Resumen» (periodo, periodo anterior, filtros en texto, quién y cuándo lo generó, nota del margen, aviso de historial incompleto, y los KPI con variación) y una hoja por desglose: compañía, sucursal, vendedor, operador, categoría, producto, cliente, mes y día.
- **CSV (`ventas-por-<dimensión>-<desde>_<hasta>.csv`):** un desglose, UTF-8 con BOM para que Excel lo abra bien.
- Las cifras son las mismas consultas del dashboard (coinciden con la pantalla) y respetan el ámbito del usuario. Los importes salen como números, no como texto formateado, para poder sumarlos. Hasta 5 000 filas por desglose.
- Los textos que empiezan con `= + - @` se neutralizan en el CSV (evita fórmulas inyectadas desde los archivos importados). En Excel se escriben como texto.
- **Auditoría:** cada descarga queda registrada como `sales.exported` (usuario, formato, dimensión, periodo y filtros). Una petición inválida o sin permiso no se registra.
- Medido en PostgreSQL con los datos demo: 12 meses completos en Excel, 64 KB en 0,4 s.

Pendiente: PDF para impresión, si se necesita.

### Paso 5 — Rankings de producto (implementado)
`GET /api/v1/sales/rankings?dimension=product|category&limit=1..50` (por defecto producto y 10), con el mismo periodo y filtros del dashboard y el mismo ámbito por usuario. Devuelve cuatro listas: `by_net_total`, `by_units`, `by_documents` (frecuencia) y `by_margin`. En la pantalla `/ventas` es la sección «Rankings», con selector Productos / Categorías; elegir un nombre filtra todo el tablero.

- **Frecuencia** = facturas distintas que incluyen el artículo (no líneas ni unidades).
- **Margen:** ranking por monto de margen estimado, con el costo **actual**; solo entran artículos con al menos una línea con costo válido (> 0). Se muestra también el porcentaje.
- Los empates se resuelven por clave, de forma estable.
- Corrección aplicada al desglose general: al ordenar por `margin`, los grupos sin costo van al final tanto en PostgreSQL como en SQLite (`desc nulls last`); antes PostgreSQL los habría puesto primero.
- Verificado en PostgreSQL con los datos demo: los tres primeros de cada ranking coinciden con una consulta SQL directa.

### Siguiente
Comparación libre de periodos (dos rangos elegidos por el usuario) y metas mensuales por vendedor (requiere definir la regla de metas, decisión #16). Con ello se cierra la Fase 3, junto con la revisión visual en navegador pendiente.
