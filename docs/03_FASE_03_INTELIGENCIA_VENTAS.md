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

### Siguiente
Paso 3: pantalla del dashboard ejecutivo (filtros, tarjetas de KPI, gráficos y tablas) y opción de menú condicionada a `sales.view`. Después: metas mensuales por vendedor, rankings de producto y exportación.
