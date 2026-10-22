# ADR-006 — Crédito e inventario como libros de movimientos inmutables

- **Estado**: Aceptado · **Fecha**: 2026-10-20 · **Relacionado**: `docs/06_FASE_06_CREDITO_COBRANZAS.md`, `docs/07_FASE_07_INVENTARIO.md`

## Contexto
El saldo de una factura de crédito y las existencias de un producto deben poder explicarse y auditarse: quién cambió qué y por qué.

## Decisión
Los saldos **no se editan**: se calculan como la suma de **movimientos** (abonos, notas de crédito, entradas, salidas, ajustes, traslados). Los movimientos son **inmutables**; corregir es agregar una **reversa** vinculada (en traslados, ambas patas o ninguna). El inventario no admite negativos y las salidas de ventas son idempotentes (clave de origen). Los costos solo los ve quien tiene el permiso.

## Consecuencias
- Historia completa y reproducible; el kárdex es una consulta, no una tabla aparte.
- Los reportes recalculan saldos desde los movimientos: hay que cuidar índices y volumen.
- No existe «editar el saldo»; la interfaz ofrece la acción de negocio que corresponde (abonar, ajustar, reversar).

## Alternativas descartadas
Columna de saldo actualizada en sitio (pierde la historia y se desincroniza).
