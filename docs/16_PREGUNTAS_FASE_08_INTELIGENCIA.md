# Preguntas para el responsable — Fase 8 (Inteligencia comercial avanzada)

Contexto: la Fase 8 convierte el historial de ventas en segmentos y recomendaciones. Solo se activa cuando hay historial suficiente, y cada recomendación debe poder rastrearse a una regla y a sus datos. Cada pregunta trae una propuesta; basta con responder «de acuerdo» o corregirla.

## 1. Historial disponible
1. **¿Cuántos meses de ventas reales tendrán cargados?** Hoy el sistema solo tiene unos días de ventas reales y 12 meses de datos de demostración. *(Propuesta de mínimos: RFM con 6 meses; cohortes y cambios anormales con 6 meses; estacionalidad con 24 meses; venta cruzada con 1.000 facturas. Por debajo, la función no se activa y la pantalla lo explica.)*
2. **¿Se cargará historial anterior** (2024 y 2025) para poder calcular estacionalidad y comparar contra el mismo mes del año anterior?

## 2. Segmentación RFM
3. **Periodo de análisis:** *(Propuesta: últimos 12 meses.)*
4. **Valor monetario:** ¿venta neta del periodo, descontando las devoluciones (notas de crédito)? *(Propuesta: venta neta menos devoluciones.)*
5. **Segmentos con nombre.** *(Propuesta: Campeones, Leales, Prometedores, Nuevos, En riesgo, Dormidos y Perdidos, a partir de quintiles de recencia, frecuencia y valor calculados por compañía.)* ¿Prefieren otros nombres o un número distinto de segmentos?
6. **¿La segmentación es por compañía o del grupo completo?** *(Propuesta: por compañía, igual que la clasificación ABC.)*

## 3. Riesgo de inactividad y cambios anormales
7. **Riesgo de inactividad:** hoy un cliente es inactivo a los 30 días y perdido a los 90 sin comprar. ¿Quieren además un aviso temprano **relativo a la frecuencia propia de cada cliente** (p. ej. cuando pasa más del doble de su intervalo habitual entre compras)? *(Propuesta: sí, como alerta de «riesgo», antes de que pase a inactivo.)*
8. **Cambio anormal:** ¿a partir de qué caída o alza avisar? *(Propuesta: venta del mes actual contra el promedio de los 3 meses anteriores del mismo cliente, con una caída del 40 % o más, o un alza del 100 % o más, y solo para clientes con al menos 3 compras en esos meses.)*
9. **Productos o líneas que el cliente dejó de comprar** (p. ej. compraba filtros cada mes y no los compra hace 3 meses): ¿les sirve este aviso? *(Propuesta: sí, a nivel de línea.)*

## 4. Venta cruzada
10. **¿A qué nivel?** *(Propuesta: línea o categoría; por producto solo si el catálogo tiene suficiente volumen.)*
11. **Regla de oportunidad:** «los clientes que compran A también compran B en el N % de los casos y este cliente aún no compra B». *(Propuesta: N = 30 % y al menos 20 clientes que respalden la regla.)*

## 5. Cohortes, estacionalidad y concentración
12. **Cohortes:** ¿por mes de primera compra y retención mensual durante 12 meses? *(Propuesta: sí.)*
13. **Concentración:** ¿cuántos clientes y productos suman el 80 % de la venta, y qué porcentaje representan los 10 principales? ¿Quieren un aviso cuando un cliente supere cierto porcentaje de la venta de la compañía? *(Propuesta: aviso informativo a partir del 15 %.)*
14. **Estacionalidad:** ¿por mes del año, a nivel de compañía y de línea? *(Propuesta: sí, activada con 24 meses de historial.)*

## 6. Alertas y recomendaciones
15. **¿Quién ve las recomendaciones?** *(Propuesta: el vendedor, las de sus clientes; el supervisor, las de su equipo; gerencia y administración, todas.)*
16. **Cierre de una alerta:** *(Propuesta: lista de motivos —ya contacté al cliente, no aplica, el cliente no desea ser contactado, resuelto, otro con nota— y que quede registrado quién y cuándo.)*
17. **¿Una alerta puede crear una acción en la agenda de seguimiento** del vendedor? *(Propuesta: sí, con un botón; nunca de forma automática.)*
18. **¿Cada cuánto se recalcula?** *(Propuesta: cada noche, mostrando siempre la fecha de cálculo y el periodo usado.)*
19. **Parámetros (umbrales, periodos, mínimos de historial):** ¿quién puede verlos y cambiarlos? *(Propuesta: los ve quien consulte las recomendaciones; solo un permiso aparte los cambia, con Auditoría.)*
