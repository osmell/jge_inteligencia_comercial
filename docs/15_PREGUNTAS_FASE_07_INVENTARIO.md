# Preguntas para el responsable — Fase 7 (Inventario)

Contexto: las ventas ya traen `Und.Vendidas` (a veces fraccionaria, p. ej. 0,00097656) sin columna de unidad, y el catálogo de productos trae código, nombre y Niveles 1 a 3. Para calcular existencias de forma confiable necesitamos estas definiciones (decisiones #11, #14 y #15). Cada pregunta trae una propuesta; basta con responder «de acuerdo» o corregirla.

## 1. Unidades y equivalencias (#15)
1. **¿En qué unidad vienen las `Und.Vendidas`?** ¿En la unidad base del producto (la menor que se controla) o en la unidad en que se vendió (caja, paquete, metro…)? *(Propuesta: unidad base; si no, el archivo debe traer una columna de unidad.)*
2. **Cantidades fraccionarias:** ¿qué significa un valor como 0,00097656 (1/1024)? ¿Es una conversión de otra unidad o un error de captura?
3. **Unidad base de cada producto:** ¿cuál es? (unidad, litro, metro, kilo, juego…). ¿Existe en algún archivo o hay que definirla producto por producto?
4. **Equivalencias:** por ejemplo «1 caja = 12 unidades». ¿Son específicas de cada producto (no hay contenido universal de cajas o paquetes)? ¿Cuántos productos las tienen y quién las mantiene? *(Propuesta: se importan en el catálogo y solo las cambia un usuario con permiso, con auditoría.)*
5. **Catálogo de líneas, categorías y unidades (#15):** ¿llegará como archivo? Columnas esperadas: código de producto, unidad base, unidades alternas, factor de cada una. ¿Se confirma que Nivel 1 = línea y Nivel 2 = categoría?

## 2. Inventario inicial (#14)
6. **Fecha de corte:** ¿cuál es por sucursal? Las existencias se calculan desde un inventario inicial conciliado; sin él no se muestran saldos. *(Propuesta: una fecha de corte por sucursal; los movimientos anteriores se ignoran.)*
7. **Plantilla del inventario inicial:** ¿qué columnas trae? *(Propuesta: compañía, sucursal, código de producto, unidad, cantidad, costo unitario opcional.)* ¿Se puede entregar por sucursal?
8. **Conciliación:** ¿quién confirma que el conteo inicial es correcto y cómo se maneja una diferencia (ajuste con motivo y autorización)?

## 3. Entradas (#11)
9. **Plantilla de entradas:** ¿qué columnas trae? *(Propuesta: fecha, compañía, sucursal, documento y serie, proveedor, código de producto, unidad, cantidad, costo unitario.)* ¿Cada cuánto se envía y es acumulado o solo lo nuevo?
10. **Compras al proveedor y devoluciones a proveedor:** ¿vienen en el mismo archivo? ¿Cómo se distinguen?
11. **¿El costo de la entrada reemplaza el `Costo Unit.Vta.` actual del catálogo** o son cosas distintas? (Hoy el margen se estima con el costo actual.)

## 4. Salidas, devoluciones, traslados y ajustes
12. **Salidas por ventas:** ¿cada línea de venta descuenta existencias de la sucursal de la factura? *(Propuesta: sí; reimportar una venta no duplica la salida.)*
13. **Devoluciones de clientes:** ¿reingresan al inventario? ¿Siempre, o solo si el producto está en buen estado? *(Propuesta: la nota de crédito no mueve inventario por sí sola; el reingreso se registra aparte, con motivo.)*
14. **Traslados entre sucursales o compañías:** ¿existen? ¿Cómo se registran hoy (documento, archivo)? ¿Hay tránsito entre la salida y la entrada?
15. **Ajustes** (mermas, daños, conteos): ¿quién los autoriza y qué motivos usan? *(Propuesta: lista de motivos fija, autorización de supervisor o gerencia, siempre con auditoría.)*

## 5. Reportes y reglas de negocio
16. **Existencias negativas:** si una venta deja el saldo por debajo de cero (venta sin entrada registrada), ¿se permite y se avisa, o se rechaza? *(Propuesta: se permite y queda marcado para conciliar.)*
17. **Rotación y cobertura:** ¿qué indicadores usan hoy (días de inventario, rotación mensual, productos sin movimiento en N días)? ¿Cuántos días sin salida consideran «sin rotación»?
18. **Mínimos y máximos:** ¿manejan existencia mínima por producto y sucursal? ¿Debe haber alertas de reposición?
19. **Valoración:** ¿el inventario se valora a costo promedio, último costo o costo estándar?
20. **Visibilidad:** ¿qué rol puede ver existencias y costos? *(Propuesta: permiso aparte; el vendedor ve solo disponibilidad, no costos.)*

## 6. Muestras para validar
21. **Un inventario real** de una sola sucursal (aunque sean 20 productos) con su conteo, y las entradas y ventas de ese mismo periodo, para comprobar que el saldo calculado coincide con el real.
22. **Dos o tres productos con equivalencias reales** (caja/unidad, litro/galón…) con el factor correcto, para comprobar las conversiones.
