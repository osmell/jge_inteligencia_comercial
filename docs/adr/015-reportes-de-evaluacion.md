# ADR-015 — Reportes de evaluación (vendedores, operadores, sucursales y conciliación)

- **Estado**: Aceptado · **Fecha**: 2026-10-23 · **Relacionado**: `docs/29_REPORTES.md`, `docs/28_SEGURIDAD.md`, ADR-011 (ports y servicios), ADR-012 (contrato de API)

## Contexto
El cliente quiere evaluar con rigor a vendedores, operadores y sucursales, contrastar las cifras consolidadas con el detalle de facturación y sacar los reportes a pantalla, impresión, Excel, PDF y correo. Ya existían el tablero de Ventas (desgloses) y Metas; faltaba una capa de **evaluación y comparación**, con indicadores definidos de forma estricta.

## Decisión
- **Un permiso, `reports.view`** (administrador, gerente general y supervisor de ventas; los dos últimos lo reciben solo la primera vez que se crea el permiso: después manda «Roles y permisos»). El vendedor no los ve.
- **Alcance por compañía** para vendedores, operadores y conciliación (quien supervisa evalúa a todo el equipo de su compañía aunque tenga pocas sucursales asignadas); **por sucursales asignadas** para el reporte de sucursales (es una unidad del negocio, no una persona del equipo). Un identificador de otra compañía responde 404; una compañía pedida a mano fuera del alcance no devuelve nada.
- **Servicios pequeños y reutilizables**: cifras de venta (`SalesFigures`), cartera (`PortfolioFigures`), cobranza (`CreditFigures`), contraste (`Reconciliation`) y listado de documentos (`DocumentListing`) sirven a los cuatro reportes; cada reporte (`SellerReport`, `OperatorReport`, `BranchReport`, `ConciliationReport`) solo los compone. Ninguno pasa de 400 líneas (lo verifica `ArchitectureTest`).
- **Semáforos con umbrales por omisión** en `config/reports.php` (un indicador desconocido es un error, no un verde silencioso; sin dato nunca es cero). Los umbrales son provisionales hasta validarlos con el cliente.
- **No se inventa lo que el sistema no sabe**: sin margen (el costo del archivo es el actual, no el histórico), sin anulaciones por operador, existencias solo en cantidades (nunca costos ni valuación, que exigen `inventory.costs`), metas incompletas no se califican y las cifras «a la fecha» (cartera, cobranza, existencias) se rotulan.
- **Una sola forma de salida**: cada reporte se convierte en un `ReportDocument` neutro que dibujan Excel (cifras como números, nivel escrito), PDF (tablas anchas partidas en bloques) y el correo. La impresión usa el propio HTML con estilos de impresión.
- **El correo es lo único irreversible**: direcciones escritas a mano, revisión y confirmación expresa (también exigida por la API), un correo por destinatario con el archivo adjunto, tope por persona y hora, rastro en Auditoría con las direcciones, y si el correo no está configurado no se intenta por otra vía.
- **Todo reporte deja rastro** (`report.viewed`, `report.exported`, `report.emailed`).
- **Contrato**: las respuestas se tipan con `@response` en los controladores y el frontend deriva sus tipos de `openapi.json`.

## Consecuencias
- Un supervisor limitado a una sucursal ve, en vendedores, operadores y conciliación, el resultado completo de su compañía; en el reporte de sucursales, solo las suyas. Es una decisión de negocio revisable (`docs/29_REPORTES.md`).
- La verificación de este apartado incluye ejecutar **toda la suite en PostgreSQL** (`composer test:pgsql`, y un trabajo propio en la CI): al hacerlo apareció un error real fuera de los reportes (un tipo de mensaje de WhatsApp más largo que su columna, que en PostgreSQL impedía enviar mensajes desde Conversaciones). Con SQLite no se veía.
- Medido en PostgreSQL con 25 000 documentos y 150 000 líneas (un año): cada reporte responde en 0,2–1,3 s y una descarga en 1,4–2,9 s.

## Alternativas descartadas
Reportes calculados en una tabla resumen precalculada (más rápida, pero otra fuente que conciliar y que puede quedar desactualizada) y un reporte único con pestañas (mezcla alcances distintos y dificulta los permisos y las salidas por reporte).
