# Fase 06 — Crédito y cobranzas

## Objetivo
Controlar facturas, abonos, saldos y comportamiento de pago con trazabilidad.

## Alcance
- Importación de facturas de crédito con plantilla por recibir.
- Registro manual de abonos/cancelaciones y asignación a facturas.
- Saldos calculados, antigüedad de cartera y reportes.
- Promesas de pago y seguimiento.
- Scoring explicable y configurable tras validar la calidad de datos.

## Reglas
- Saldo = importe original − abonos aplicados − ajustes/créditos autorizados.
- No modificar el saldo directamente.
- No permitir sobrepago sin flujo explícito de anticipo/saldo a favor.
- Registrar usuario, fecha, método y referencia del abono.
- Anulaciones/correcciones auditadas; no borrar movimientos contabilizados.
- Separar scoring de pago de potencial comercial.
- No aprobar automáticamente límites de crédito.

## Aceptación
Saldos reproducibles desde movimientos; antigüedad correcta; cada cambio financiero auditado.

## Respuestas recibidas (2026-10-20)
1. **Plantilla de facturas de crédito** (una fila por factura): Fecha, Descuento, Subtotal, Impuesto, Total, Documento, Serie, Código Cliente, Nombre Cliente, Nombre Vendedor, Nombre Operador, Sucursal, Compañía.
2. **Es la misma factura de venta** (compañía + serie + documento); el archivo no trae fecha de vencimiento. La antigüedad se cuenta **desde la fecha de la factura** con los tramos: 1-30, 31-60, 61-90, 91-120 y más de 120 días (lo que aún no cumple 1 día es «Al día»). Configurables en `config/credit.php`.
3. **Una sola moneda (NIO).** No existe campo de moneda.
4. **Devoluciones:** de contado, la factura se anula el mismo día (no llega al archivo); de crédito, una **nota de crédito** (Fecha, Documento, Serie, Código Cliente, Nombre Cliente, Observaciones, Subtotal, Impuesto, Total) que puede ser parcial o total. **Solo el administrador o el supervisor** las registran.

Decisiones tomadas con esas respuestas: la nota de crédito se **captura en pantalla** y quien la registra **elige la factura** del cliente a la que aplica (puede repartirla entre varias); descuenta del saldo de la factura y también de las ventas del cliente.

## Avance
### Paso 1 — Importación de facturas de crédito (hecho)
- Tipo de archivo **Facturas de crédito** en Importaciones (plantilla descargable, vista previa, confirmación, errores por fila y columna).
- Reglas: Subtotal − Descuento + Impuesto = Total (tolerancia configurable); sin importes negativos ni total en cero; descuento vacío = 0; compañía y sucursal deben existir; vendedores y operadores nuevos se dan de alta, como en ventas.
- Una factura ya importada con los mismos datos se omite; con datos distintos es un error (`document_conflict`): no se sobrescribe, una corrección es un movimiento.
- **Enlace con la venta:** si la factura de venta ya está cargada se enlaza y se comparan importes y cliente (avisos `sales_mismatch` y `sales_customer_mismatch`, no errores). Si la venta llega después, se enlaza al importar las ventas.

### Paso 2 — Libro de movimientos y saldos (hecho)
- Tablas `credit_invoices` (inmutables) y `credit_movements` (inmutables, con importe con signo). Al importar, cada factura **abre su saldo** con un movimiento «factura».
- **El saldo es la suma de los movimientos**; no existe forma de editarlo. `CreditLedger` ofrece abonos, notas de crédito, ajustes (con nota obligatoria) y **anulaciones** que crean un movimiento contrario enlazado (el original queda intacto y no se anula dos veces).
- **Sin sobrepago:** ningún movimiento puede dejar el saldo en negativo; la fecha no puede ser anterior a la factura ni futura; la factura original no se anula. Cada movimiento queda en Auditoría (`credit.movement_*`) con usuario, importe, método y referencia.
- Pantallas **Crédito** (`/credito`, permiso `credit.view`): facturas con total, saldo, antigüedad en texto y filtros por compañía, estado, tramo y texto; detalle con sus movimientos. Un vendedor solo ve las facturas que le corresponden como vendedor.

### Paso 3 — Abonos y notas de crédito en pantalla (hecho)
- Permisos nuevos, que se otorgan en «Roles y permisos» (no se dan solos): `credit.payments` (registrar y anular abonos) y `credit.notes` (registrar y anular notas de crédito; se asigna solo a administrador y supervisor). `credit.view` solo consulta.
- En el detalle de una factura con saldo: **Registrar abono** y **Registrar nota de crédito**. La captura carga las facturas con saldo del mismo cliente (de la más antigua a la más reciente); se reparte a mano o con «Aplicar a las más antiguas».
- **Abono:** fecha (no futura ni anterior a la factura), método obligatorio, referencia y nota; se puede repartir entre varias facturas del cliente.
- **Nota de crédito:** fecha, serie, documento, subtotal, impuesto, total y observaciones. Subtotal + impuesto = total, y **lo aplicado a las facturas debe ser igual al total**: un excedente no se acepta como saldo. Se guarda en `credit_notes` (inmutable, única por compañía + serie + documento).
- **Todo o nada:** si una parte supera el saldo de su factura (sobrepago) no se registra ninguna. Cada movimiento y cada nota quedan en Auditoría.
- **Anulación:** un abono se anula solo (con motivo); una nota de crédito se anula completa en todas las facturas que tocó. El original se conserva marcado como «Anulado». Los ajustes no se anulan por pantalla.
- **Devoluciones frente a las ventas (decidido):** la nota no trae líneas por producto, así que no se mezcla con las ventas importadas ni con los rankings: se muestra aparte, por cliente y compañía, en la ficha del cliente («Crédito y devoluciones»), con «Venta neta menos devoluciones» (subtotal de las notas vigentes, sin impuesto).

### Paso 4 — Antigüedad de cartera, exportación y crédito en la ficha (hecho)
- Pantalla **Antigüedad de cartera** (`/cartera`, permiso `credit.view`): saldo por cliente en los tramos 1-30, 31-60, 61-90, 91-120 y más de 120 días (más «Al día»), totales por tramo, filtro por compañía, y exportación a **CSV** con todos los clientes (queda en Auditoría, `credit.aging_exported`). En pantalla se ven los 300 clientes con mayor saldo.
- Respeta el ámbito del usuario: un vendedor solo ve la cartera de sus facturas.
- Ficha del cliente: sección «Crédito y devoluciones» (visible con `credit.view`) con saldo por tramo, factura más antigua y devoluciones vigentes (las notas anuladas no cuentan).
- No incluye todavía exportación a Excel/PDF de la cartera ni filtro por vendedor.

### Pendiente
5. Promesas de pago. 6. Scoring.
