# Preguntas para el responsable — Fase 4 (Cliente 360°)

Contexto: la ficha de solo lectura ya funciona con las ventas importadas. Para pasar a datos maestros, asignaciones y alertas necesitamos estas definiciones (decisiones #9, #17, #18, #19 y la regla de códigos duplicados).

## 1. Identidad del cliente
1. **¿El código de cliente es único en todo el grupo o por compañía?** Hoy lo tratamos como un solo cliente aunque compre en varias compañías (se avisa en la ficha).
   - a) Único en el grupo: un código = un cliente. *(así se comporta hoy)*
   - b) Por compañía: el mismo código en otra compañía puede ser otra persona.
2. **Si un mismo código llega con nombres distintos en facturas diferentes** (p. ej. «Taller Pérez» y «TALLER PEREZ S.A.»), ¿cómo se resuelve?
   - a) Gana el nombre más reciente y se conserva el historial de variantes. *(recomendada)*
   - b) Alguien lo revisa y elige el nombre oficial.
   - c) Se rechaza la importación hasta corregir el archivo.
3. **Si dos códigos distintos son en realidad el mismo cliente** (duplicado), ¿quién los une y se debe poder deshacer? *(Propuesta: unión solo manual, con auditoría y reversible.)*

## 2. Plantilla de clientes (#9)
4. ¿Habrá un archivo maestro de clientes? Si sí, ¿qué columnas trae (código, nombre, tipo, NIT o documento, teléfono, correo, dirección, contacto, vendedor asignado, fecha de alta, estado)? ¿Cada cuánto se envía y es completo o solo cambios?
5. **Tipos de cliente:** ¿cuáles son (mayorista, taller, flota, consumidor final…)? ¿Quién puede crear o cambiar tipos?
6. **Clasificación comercial** (independiente del tipo): ¿qué categorías usan hoy (A/B/C, estratégico…) y con qué criterio?

## 3. Asignación de vendedores (#19)
7. **¿Cuántos vendedores puede tener un cliente?**
   - a) Uno solo. b) Varios, con un responsable principal opcional. *(es lo que indica el diseño)* c) Varios, con responsable obligatorio.
8. **¿Qué ve cada rol?**
   - Vendedor: a) solo sus clientes asignados, b) todos los de su sucursal, c) todos.
   - Supervisor de ventas: a) los clientes de sus vendedores, b) los de su sucursal, c) todos los de la compañía.
9. **¿Quién asigna y reasigna clientes?** (Gerencia general, supervisores, administración). ¿Queda un historial de cambios de asignación?
10. **Cliente sin vendedor asignado:** ¿lo ve cualquiera con permiso, solo gerencia o se avisa para asignarlo?
11. **Si un vendedor factura a un cliente que no tiene asignado,** ¿es normal (mostrador) o debe avisarse?

## 4. Reglas comerciales (#17 y #18)
12. **Periodo de inactividad:** ¿cuántos días sin comprar para considerar a un cliente inactivo? ¿Es igual para todos o depende de su frecuencia habitual (p. ej. «el doble de su promedio entre compras»), del tipo de cliente o de la línea de negocio?
13. **Cliente nuevo:** ¿primera compra en los últimos X días? ¿Cuánto vale X? Con datos demo desde noviembre de 2025 no podemos saber si alguien es realmente nuevo antes de esa fecha: ¿existe un historial anterior que se pueda cargar?
14. **Cliente recuperado:** ¿vuelve a comprar después de estar inactivo? ¿Con cuántos días de inactividad previos?
15. **Cliente perdido:** ¿cuántos días sin comprar, o cuántos periodos de inactividad seguidos?
16. **¿Las reglas se calculan por cliente en total o por línea de negocio / categoría?** (Un cliente puede seguir comprando filtros y haber dejado de comprar frenos.)

## 5. Para validar totales
17. **Un reporte de ventas por cliente que consideren correcto** (aunque sea de un solo mes y 5 clientes), para contrastar la venta neta y los documentos de la ficha.
18. **Devoluciones y notas de crédito (#13):** ¿restan a la venta del cliente cuando lleguen?
19. **Contactos:** ¿se necesitan varios por cliente (compras, pagos, taller) y con qué datos?

*Si hay poco tiempo: con las respuestas a 1, 2, 7, 8 y 9 se puede construir la tabla de clientes y la asignación de vendedores. Las preguntas 12–16 desbloquean las alertas.*
