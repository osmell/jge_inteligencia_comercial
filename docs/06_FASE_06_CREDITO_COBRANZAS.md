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
