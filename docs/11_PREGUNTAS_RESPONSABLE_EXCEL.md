# Preguntas para el responsable del sistema que genera el Excel de ventas

**Para:** persona que genera el archivo `Plantilla Ventas.xlsx` (hoja `Hoja1`).
**De:** equipo de JGE Inteligencia Comercial.
**Objetivo:** confirmar qué significa cada dato antes de construir la importación, para no calcular ventas, márgenes ni tendencias con suposiciones.

Basta con responder marcando una opción o escribiendo una línea. Si una respuesta es "depende", indique de qué.

El archivo actual tiene estas 18 columnas: Documento, Serie, Código Cliente, Nombre Cliente, Código del Producto, Und.Vendidas, Nombre del Producto, Costo Tot.Vta., Costo Unit.Vta., Precio Unit.Vta., Precio Unit.Normal, Total Neto, Total Impuesto, Venta + Imp, Gran Total, Nombre Vendedor, Nombre Nivel 2 Inv, Nombre Facturador.

---

## 1. Fecha de la venta
El archivo no trae ninguna fecha. Sin ella no se pueden ver ventas por día, mes o año.

- ¿El sistema puede incluir una columna con la **fecha de la factura**?
  - [ ] Sí, se puede agregar al mismo archivo. Nombre de la columna: ________
  - [ ] No, pero existe en otro reporte o archivo: ________
  - [ ] No existe
- Si el archivo se genera cada día, ¿contiene solo las ventas de ese día o un rango? Rango habitual: ________
- ¿También viene la **hora**? [ ] Sí [ ] No

## 2. Compañía y sucursal
JGE tiene varias compañías y cada una tiene sucursales. El archivo no indica a cuál pertenece cada venta.

- ¿Cómo sabemos a qué compañía y sucursal pertenece cada documento?
  - [ ] Se puede agregar una columna de **compañía** y otra de **sucursal**
  - [ ] Se genera un archivo distinto por compañía y/o sucursal (el nombre del archivo lo indica)
  - [ ] Se deduce de la **Serie** (indique la tabla de equivalencias: serie → compañía/sucursal)
  - [ ] Otra forma: ________
- Si es por serie: ¿una misma serie puede repetirse en dos compañías o sucursales distintas? [ ] Sí [ ] No

## 3. Qué identifica una factura de forma única
- ¿La combinación **Documento + Serie** es única dentro de una compañía? [ ] Sí [ ] No
- ¿Puede repetirse el mismo número de documento en otro año, otra sucursal o con otro tipo de documento? Detalle: ________
- Si se importa dos veces el mismo documento (por ejemplo, un archivo que se solapa con el anterior), ¿cuál es la forma correcta de reconocerlo como el mismo? ________

## 4. Importes: qué significa cada columna
En algunas filas de la muestra, `Venta + Imp` y `Gran Total` no coinciden, y `Total Neto` más `Total Impuesto` tampoco siempre da el total.

Para una línea, indique con una fórmula o frase qué es cada una:

| Columna | ¿Qué representa? | ¿Incluye impuestos? | ¿Incluye descuentos? |
|---|---|---|---|
| Total Neto | | | |
| Total Impuesto | | | |
| Venta + Imp | | | |
| Gran Total | | | |

- ¿Cuál de estas columnas es el **importe oficial** de la factura? ________
- Cuando `Venta + Imp` y `Gran Total` difieren, ¿cuál es la razón? (descuento global, redondeo, otros cargos, error de captura…) ________
- ¿`Gran Total` se repite en cada línea del mismo documento o es el total de toda la factura? [ ] Se repite [ ] Solo en una línea [ ] Es el de la línea
- ¿Hay descuentos? ¿Dónde se ven? (diferencia entre `Precio Unit.Normal` y `Precio Unit.Vta.`, otra columna…) ________

## 5. Costos
- `Costo Tot.Vta.` ¿es el costo total de la línea (cantidad × costo unitario)? [ ] Sí [ ] No
- `Costo Unit.Vta.` ¿es el costo que tenía el producto **en el momento de la venta** o el costo **actual** del producto? [ ] Al momento de la venta [ ] Actual
- ¿El costo incluye impuestos, fletes u otros componentes? ________
- ¿Hay ventas con costo en cero o vacío? ¿Qué significa? ________

## 6. Significado de "Nombre Nivel 2 Inv"
- ¿Qué clasificación es? [ ] Línea de productos [ ] Categoría [ ] Subcategoría [ ] Marca [ ] Otra: ________
- ¿Cuál es el nivel 1 y el nivel 3, si existen? Nivel 1: ________ Nivel 3: ________
- ¿Se puede obtener la lista completa de valores posibles? [ ] Sí, la adjunto [ ] No

## 7. Anulaciones, devoluciones y notas de crédito
- ¿Las **facturas anuladas** aparecen en el archivo? [ ] Sí [ ] No. Si aparecen, ¿cómo se distinguen? ________
- ¿Las **devoluciones** o **notas de crédito** aparecen? [ ] Sí [ ] No
  - Si aparecen: ¿vienen con cantidades e importes **negativos**? [ ] Sí [ ] No
  - ¿Cómo se relacionan con la factura original (número de documento, serie)? ________
- ¿Hay otros tipos de documento en el archivo (cotizaciones, facturas de crédito, ventas al contado, pedidos)? ¿Cómo se distinguen? ________

## 8. Facturadores
- `Nombre Facturador` ¿es la persona que emitió la factura en el sistema? [ ] Sí [ ] No. Si no, ¿qué es? ________
- Para medir tiempos de facturación y errores necesitaríamos la **fecha y hora de emisión** de cada factura, y las **anulaciones o correcciones** con su motivo. ¿El sistema registra esos datos? [ ] Sí [ ] No
  - Si sí: ¿se pueden incluir en el archivo o en otro reporte? ________

---

## Archivos que necesitamos
1. **Una muestra de 30 a 50 líneas** de varias facturas, con la fecha y la compañía/sucursal ya incluidas. Debe contener, si existen, al menos: una factura con varias líneas, una con descuento, una **anulada**, una **devolución o nota de crédito**, y una donde `Venta + Imp` y `Gran Total` no coincidan. Puede cambiar nombres de clientes por códigos; no es necesario compartir datos sensibles.
2. La **lista de valores** de "Nombre Nivel 2 Inv" y la lista de series con su compañía/sucursal.
3. Confirmación de **cómo y cuándo se genera** el archivo diario (hora, quién lo exporta, formato `.xlsx`).

Con estas respuestas se cierran los puntos bloqueantes 1 a 8 de `10_DECISIONES_PENDIENTES.md` y se puede iniciar la Fase 2 (importación y calidad de datos).
