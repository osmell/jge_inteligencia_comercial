# Decisiones pendientes — JGE Inteligencia Comercial

No cerrar estas preguntas mediante suposiciones.

## Bloqueantes para importación y KPI
1. **Fecha de venta:** no aparece en la plantilla recibida. ¿Se obtiene de otro archivo o puede agregarse?
2. **Compañía y sucursal:** no aparecen explícitamente. ¿Cómo se determina cada documento?
3. **Importes:** algunas filas muestran diferencias entre `Venta + Imp` y `Gran Total`. ¿Cuál es el importe oficial y qué representa cada columna?
4. **Costo:** confirmar si `Costo Tot.Vta.` es costo total de línea y `Costo Unit.Vta.` es costo histórico unitario.
5. **Nombre Nivel 2 Inv:** confirmar si es línea, categoría u otra clasificación.
6. **Clave de documento:** confirmar si `Documento` + `Serie` identifica una factura de forma única dentro de la compañía.
7. **Anulaciones/devoluciones:** cómo se representan y afectan ventas/inventario.
8. **Facturadores:** ¿se puede obtener fecha/hora de emisión, anulaciones y errores para medir tiempos/calidad?

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
Resolver primero los puntos 1–6 con la persona responsable del sistema que genera el Excel. Sin fecha y dimensiones empresariales no se pueden calcular correctamente tendencias temporales ni separar ventas por compañía/sucursal.
