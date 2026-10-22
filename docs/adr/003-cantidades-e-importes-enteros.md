# ADR-003 — Importes y cantidades como enteros escalados, nunca `float`

- **Estado**: Aceptado · **Fecha**: 2026-10-19 · **Relacionado**: `docs/07_FASE_07_INVENTARIO.md`, `App\Imports\Decimal`, `App\Inventory\Quantity`

## Contexto
Los Excel traen cantidades con muchos decimales (por ejemplo 0,00097656 de una unidad) y los importes deben cuadrar al centavo (el total debe ser neto + impuesto). El `float` acumula errores de redondeo, y `bcmath` no está garantizado en el servidor.

## Decisión
- **Importes**: enteros con 4 decimales (`Decimal`).
- **Cantidades de inventario**: enteros con 8 decimales (`Quantity`); las equivalencias de unidades son fracciones reducidas con denominador que divide a 10^6; la valorización multiplica en partes de 4 dígitos para no desbordar 64 bits.
- Las conversiones redondean **mitad hacia arriba** en magnitud y lanzan excepción ante desbordamiento. Nunca se corrigen importes en silencio: lo que no cuadra se reporta como error de la fila.

## Consecuencias
- Resultados exactos y repetibles; pruebas unitarias baratas (`QuantityTest`, `DecimalTest`).
- Hay que usar siempre estas clases al sumar o multiplicar; un `(float)` suelto en un cálculo de dinero es un defecto.
- Límite de 10 millones de unidades base por cantidad.

## Alternativas descartadas
`float`/`double`, `DECIMAL` calculado en PHP con `bcmath` (dependencia opcional del servidor).
