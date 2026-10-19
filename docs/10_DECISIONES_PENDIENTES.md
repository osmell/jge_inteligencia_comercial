# Decisiones pendientes — JGE Inteligencia Comercial

No cerrar estas preguntas mediante suposiciones.

## Respuestas recibidas del responsable del Excel (2026-10-18)
Fuente: respuesta al cuestionario `11_PREGUNTAS_RESPONSABLE_EXCEL.md`. El responsable indicó que los datos de la plantilla son **ficticios**.

| # | Tema | Respuesta | Estado |
|---|---|---|---|
| 1 | Fecha de venta | Se agregará un campo de fecha al archivo | Resuelto en parte: falta nombre exacto de la columna, formato, si incluye hora y qué rango cubre cada archivo diario |
| 2 | Compañía y sucursal | Se agregarán columnas de compañía y sucursal | Resuelto en parte: faltan nombres de las columnas y si son código o nombre (y su equivalencia con los catálogos del sistema) |
| 6 | Clave de documento | `Documento` + `Serie` es el número único | Resuelto. Pendiente solo confirmar que es único dentro de la compañía (por defecto se usará compañía + serie + documento) |
| 4 | Costo | `Costo Unit.Vta.` es el costo unitario | Parcial: no se confirmó si es costo histórico de la venta o actual, ni la semántica de `Costo Tot.Vta.` |
| 5 | Nombre Nivel 2 Inv | Nivel 2 = **categoría** de productos; existe Nivel 1 = **línea** | Resuelto. Pendiente: si la línea (Nivel 1) llegará como columna y la lista de valores |
| 7 | Anulaciones | No vienen facturas anuladas | Parcial: falta aclarar devoluciones y notas de crédito (¿vienen? ¿en negativo? ¿cómo se enlazan?) |
| 8 | Facturadores | La columna se llama `Nombre Operador` (antes `Nombre Facturador`) | Parcial: solo cambio de nombre. Sin fecha/hora de emisión no se calculan KPI de velocidad ni errores |

## Bloqueantes aún abiertos
3. **Importes (el más importante):** ¿cuál es el importe oficial y qué representan `Total Neto`, `Total Impuesto`, `Venta + Imp` y `Gran Total`? ¿Por qué difieren en algunas filas? ¿Dónde se ven los descuentos? Sin esto no se calculan ventas netas ni totales facturados.
- **Costo:** histórico de la venta o actual (punto 4 parcial).
- **Devoluciones y notas de crédito:** si aparecen y cómo (punto 7 parcial).
- **Columnas nuevas:** nombres y formatos exactos de fecha, compañía y sucursal (puntos 1 y 2).
- **Operador:** si habrá fecha/hora de emisión (punto 8 parcial).
- **Muestra revisada:** recibir el archivo actualizado con las columnas nuevas y casos difíciles (devolución, descuento, importes que no cuadran).

## Efecto sobre las fases
- **Fase 2 (importación):** se puede diseñar el marco de importación, la clave idempotente (compañía + serie + documento) y los controles de conciliación. **No** cerrar las reglas de importes hasta resolver el punto 3.
- **Fase 3 (KPI):** bloqueada para venta neta, impuesto y margen hasta resolver importes y costo.

## Plantillas por recibir
9. Clientes.
10. Productos.
11. Entradas de inventario.
12. Facturas de crédito.
13. Definición del registro de abonos/cancelaciones y notas de crédito.
14. Inventario inicial y fecha de corte por sucursal.
15. Catálogo de líneas, categorías, unidades y equivalencias.

## Reglas comerciales
16. Metas mensuales y tratamiento de vendedores nuevos/ausencias.
17. Periodos de inactividad según frecuencia de compra.
18. Definiciones de cliente nuevo, recuperado y perdido.
19. Permisos de supervisores y política de asignación múltiple.
20. Paleta corporativa final y modo oscuro opcional.

## Recomendación
Las preguntas para el responsable del Excel están redactadas en `11_PREGUNTAS_RESPONSABLE_EXCEL.md` (puntos 1–8).

El punto 3 (importes) es el principal bloqueante restante. Reenviar al responsable del Excel solo lo que sigue abierto (ver «Bloqueantes aún abiertos»).
