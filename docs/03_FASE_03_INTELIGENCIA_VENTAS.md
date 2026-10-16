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
