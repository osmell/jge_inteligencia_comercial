# Decisiones pendientes — JGE Inteligencia Comercial

No cerrar estas preguntas mediante suposiciones.

## Respuestas del responsable del Excel (2026-10-18, versión corregida)
Fuente: respuesta al cuestionario `11_PREGUNTAS_RESPONSABLE_EXCEL.md`; esta versión sustituye a la respuesta anterior. Los datos de la plantilla son **ficticios**. La numeración corresponde a la lista original de bloqueantes.

| # | Tema | Respuesta | Estado |
|---|---|---|---|
| 1 | Fecha de venta | Se agregará un campo de fecha al archivo | Resuelto en parte: falta nombre de la columna, formato, si incluye hora y qué rango cubre cada archivo diario |
| 2 | Compañía y sucursal | Se agregarán columnas de compañía y sucursal | Resuelto en parte: faltan nombres de columnas y si son código o nombre (y su equivalencia con los catálogos del sistema) |
| 3 | Importes | `Total Neto` = Subtotal − descuento · `Total Impuesto` = Total Neto × IVA · `Venta + Imp` = Total Neto + Total Impuesto · `Gran Total` = Total Neto + Total Impuesto | Resuelto en lo esencial (ver «Reglas de conciliación»). Detalles abiertos abajo |
| 4 | Costo | `Costo Unit.Vta.` es el costo unitario **actual** (no el histórico de la venta) | Resuelto. Consecuencia: el margen no es histórico (ver abajo). `Costo Tot.Vta.` sin confirmar explícitamente |
| 5 | Nombre Nivel 2 Inv | Nivel 2 = **categoría**; existe Nivel 1 = **línea** | Resuelto. Pendiente: si la línea llegará como columna y la lista de valores |
| 6 | Clave de documento | `Documento` + `Serie` es el número único | Resuelto. Se usará compañía + serie + documento salvo que se confirme que la serie es única en todo el grupo |
| 7 | Anulaciones/devoluciones | No vienen anuladas, ni devoluciones, ni notas de crédito: esos documentos se emiten **aparte** | Resuelto para esta plantilla (no habrá importes negativos). Pendiente: cómo se recibirán esos documentos aparte |
| 8 | Facturadores | La columna se llama `Nombre Operador` (antes `Nombre Facturador`) | Parcial: solo cambio de nombre. Sin fecha/hora de emisión no hay KPI de velocidad ni errores |

### Reglas de conciliación derivadas (Fase 2)
Por definición `Venta + Imp` y `Gran Total` deben ser **iguales** a `Total Neto + Total Impuesto`. Las diferencias observadas en la muestra son, por tanto, **anomalías de datos**, no dos conceptos distintos:
- Marcar como inconsistencia toda fila donde `Venta + Imp` ≠ `Gran Total` o ≠ `Total Neto + Total Impuesto` (con tolerancia de redondeo configurable).
- No corregir silenciosamente: reportar por fila y columna, según las reglas de importación del contexto maestro.
- Verificar también `Total Impuesto` = `Total Neto` × tasa de IVA (la tasa aún no está definida: ver abajo).

### Consecuencia del costo actual
Como `Costo Unit.Vta.` es el costo **actual** y no el de la fecha de la venta, el margen de ventas pasadas se calcula con un costo que pudo cambiar. Propuesta (a aprobar): conservar el costo tal como vino en cada importación diaria y rotular el margen como «margen estimado con costo actual» hasta contar con costo histórico.

## Detalles aún abiertos
- **IVA:** tasa(s) aplicables, productos exentos y si la tasa puede variar por línea.
- **Alcance de los importes:** si `Total Neto`, `Total Impuesto`, `Venta + Imp` y `Gran Total` son por línea o por documento (y si se repiten en cada línea).
- **Descuento:** dónde se ve (no hay columna de descuento; ¿diferencia entre `Precio Unit.Normal` y `Precio Unit.Vta.`?) y qué es el «Subtotal» de la fórmula.
- **Costo Tot.Vta.:** confirmar que es cantidad × `Costo Unit.Vta.`
- **Columnas nuevas:** nombres y formatos exactos de fecha, compañía y sucursal; si la fecha trae hora; rango de cada archivo diario.
- **Operador:** si habrá fecha/hora de emisión.
- **Línea (Nivel 1):** si llegará como columna.
- **Documentos aparte** (anulaciones, devoluciones, notas de crédito): plantilla y forma de enlazarlos con la factura original (relacionado con 13 y 14).
- **Muestra actualizada** con las columnas nuevas y casos difíciles (descuento, importes que no cuadran).

## Efecto sobre las fases
- **Fase 2 (importación):** implementada y verificada (ver `02_FASE_02_IMPORTACION_CALIDAD_DATOS.md`). Con las respuestas pendientes solo hay que ajustar `backend/config/imports.php`: nombres de las columnas nuevas, formato de fecha y tasa de IVA.
- **Fase 3 (KPI):** venta neta, impuesto y total facturado quedan desbloqueados tras la Fase 2. Margen: solo como estimación con costo actual. KPI de facturadores: sigue sin datos de tiempo.

## Plantillas por recibir
9. Clientes.
10. Productos.
11. Entradas de inventario.
12. Facturas de crédito.
13. Definición del registro de abonos/cancelaciones y notas de crédito.
14. Inventario inicial y fecha de corte por sucursal.
15. Catálogo de líneas, categorías, unidades y equivalencias.

## Reglas comerciales
16. ~~Metas mensuales y tratamiento de vendedores nuevos/ausencias~~ **Resuelta:** meta sobre venta neta; sugerida por el sistema y ajustable; la captura la hace Gerencia general; reglas de prorrateo propuestas aceptadas (ver `03_FASE_03_INTELIGENCIA_VENTAS.md`, paso 7).
17. Periodos de inactividad según frecuencia de compra.
18. Definiciones de cliente nuevo, recuperado y perdido.
19. Permisos de supervisores y política de asignación múltiple.
20. Paleta corporativa final y modo oscuro opcional. *(Logos recibidos: colores muestreados y aplicados; falta confirmar el tono oficial —2023 vs 2024— y decidir el modo oscuro. Ver `12_SISTEMA_DE_DISENO.md`.)*

## Recomendación
Las preguntas están en `11_PREGUNTAS_RESPONSABLE_EXCEL.md`. Reenviar al responsable solo lo que sigue abierto en «Detalles aún abiertos».
