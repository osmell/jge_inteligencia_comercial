# Lista de comprobación — aceptación de las Fases 3 y 4

Sirve para dar por aceptadas las fases de **Inteligencia de ventas** (3) y **Cliente 360** (4). Tiene dos partes: revisar las pantallas en un navegador y contrastar las cifras con un reporte que ya consideres correcto. Marca cada punto; lo que falle, anótalo con la pantalla, el usuario y qué esperabas.

## 0. Preparación

- [ ] Entorno levantado (`docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d`) y abierto en http://localhost:5173.
- [ ] Datos de demostración cargados (`php artisan db:seed --class=DemoSalesSeeder`) **o**, para la validación real, tus archivos cargados en Importaciones.
- [ ] Usuarios de desarrollo (contraseña `Desarrollo-123`): `admin` (todo), `gerente` (Alfa y Beta), `supervisor` (Alfa: Central y Norte), `vendedor1` (Alfa: Central), `vendedor2` (Beta: Central).
- [ ] Permisos: solo el Administrador los tiene todos. Para probar con `gerente` y `supervisor`, entra como `admin` a **Roles y permisos** y otórgales `sales.view`, `customers.view`, `customers.manage` y `goals.manage` según lo que quieras probar.
- [ ] Anchos de pantalla a probar en cada sección: escritorio, tableta (~768 px) y móvil (~390 px). En el navegador: F12 → modo dispositivo.

## 1. Revisión visual (todas las pantallas)

Para **cada** pantalla de las secciones 2 a 6, comprueba:

- [ ] Se ve completa en móvil sin desplazamiento horizontal de la página (las tablas anchas se desplazan dentro de su caja).
- [ ] Los botones y campos se pueden tocar con el dedo (no quedan pegados ni cortados).
- [ ] Mientras carga se ve un indicador y, si no hay datos, un mensaje claro (no una tabla vacía sin explicación).
- [ ] Ningún estado depende solo del color: siempre hay texto («Meta cumplida», «Activo»…).
- [ ] Los números salen con formato legible (miles y decimales) y las fechas en español.
- [ ] Se puede recorrer con el teclado (Tab) y el foco es visible.
- [ ] Un error provocado a propósito (p. ej., apagar el servidor) muestra un mensaje comprensible.

## 2. Ventas (`/ventas`)

- [ ] El periodo por defecto y los atajos (30 días, mes, año…) cambian las cifras.
- [ ] Indicadores: venta neta, impuesto, total facturado, descuento (ya restado de la venta neta; el % es sobre el Sub Total), unidades, documentos, clientes, ticket promedio, margen estimado, cobertura de costo.
- [ ] Cada indicador muestra su variación contra el periodo anterior **en texto** (con flecha y porcentaje).
- [ ] Comparar con «otro periodo» valida las fechas y avisa si los periodos tienen distinta duración o se traslapan.
- [ ] Con el rango anterior sin historial, aparece el aviso de comparación parcial.
- [ ] Filtros: compañía → solo ofrece sus sucursales; sucursal, vendedor, operador, categoría, producto, cliente.
- [ ] Clic en una fila de un ranking filtra todo el tablero; hay forma de quitar el filtro.
- [ ] Rankings (productos y categorías): además de importe, unidades, frecuencia y margen, hay «Por descuento» (solo artículos con descuento); el desglose trae la columna Descuento.
- [ ] Gráfica de tendencia: coincide con la tabla; el tooltip y la lectura por teclado funcionan.
- [ ] Exportar a **Excel**, **CSV** (por cada desglose) y **PDF**: se descargan, abren bien en Excel/lector y el PDF cabe en la página.
- [ ] En el CSV/Excel los textos que empiezan por `=`, `+`, `-` o `@` salen precedidos de `'`.

## 3. Metas (`/metas`)

- [ ] Elegir mes y compañía muestra una fila por vendedor con meta, venta, cumplimiento y estado en texto.
- [ ] Semáforo: verde ≥ 100 %, ámbar 70–99 %, rojo < 70 %.
- [ ] Mes en curso: aparecen ritmo, proyección y la alerta «por debajo del ritmo esperado» (solo pasado el 20 % del mes).
- [ ] **Editar metas** (usuario con `goals.manage`): capturar monto, días de ausencia, alta y baja; la meta efectiva se prorratea por días elegibles.
- [ ] **Aplicar sugerencias**: rellena con el promedio de los últimos meses más el % de crecimiento y permite ajustar antes de guardar.
- [ ] Guardar deja constancia (se marca el origen manual o sugerida) y queda en Auditoría.
- [ ] Un usuario sin `goals.manage` no ve el botón de editar; uno limitado a ciertas sucursales no ve metas.
- [ ] Exportar metas a **CSV** y **PDF**.

## 4. Clientes (`/clientes`)

### Listado
- [ ] Busca por código, nombre o nombre comercial (esperando a que termines de escribir); `%` y `_` se buscan literalmente.
- [ ] Filtros por compañía, **tipo** y **estado**; orden por última compra, venta neta, documentos o nombre; paginación de 20.
- [ ] Las columnas **Tipo**, **Vendedor** (con «por defecto» si es la Tienda), **Estado** y **ABC** son correctas.
- [ ] Incluye clientes sin compras (estado «Sin compras»).

### Ficha (`/clientes/ficha`)
- [ ] Datos de contacto, tipo, clase ABC, estado (con los plazos vigentes), vendedor, facturador y supervisor.
- [ ] Totales, ticket promedio, frecuencia, última compra («hace N días»), margen estimado, venta de 12 meses, productos/categorías/vendedores/sucursales principales.
- [ ] Aviso de historial disponible desde la fecha en que empiezan las ventas.
- [ ] Historial de compras con «Facturó» y detalle de productos por factura (abrir y cerrar).
- [ ] **Editar datos** (con `customers.manage`): cambiar nombre, contacto, teléfono, correo, dirección y sucursal; los errores salen en el campo; el código no se puede cambiar.
- [ ] Cambiar tipo, vendedor y facturador desde la ficha; «Tienda por defecto» cuando no hay vendedor.

### Catálogos (con `customers.manage`)
- [ ] **Tipos de cliente** (`/clientes/tipos`): crear, renombrar, desactivar; un tipo inactivo no se ofrece al asignar.
- [ ] **Reglas de actividad** (misma pantalla): cambiar 30/90 días; «perdido» debe ser mayor que «inactivo».
- [ ] **Vendedores** (`/clientes/vendedores`): crear, renombrar, activar/desactivar, sucursal, «Tienda por defecto» (solo una por sucursal), supervisor y usuario vinculado.
- [ ] **Facturadores** (`/clientes/facturadores`): crear, renombrar, activar/desactivar.

### Reporte de ventas por cliente (`/clientes/reporte`)
- [ ] Periodo, compañía y sucursal; periodo inválido avisa y no consulta.
- [ ] Cada cliente muestra venta neta, documentos, última compra, vendedor asignado y supervisor, **quién vendió**, **quién facturó** y **venta directa del facturador**.
- [ ] Exportar a **Excel** y **CSV**: traen todas las personas (la pantalla muestra las 3 principales) y la hoja de notas.

### Seguimiento comercial (ficha del cliente, Fase 5 paso 1)
- [ ] En **Roles y permisos**, otorga `customers.view` y `interactions.manage` a Vendedor y Supervisor de Ventas (no se otorgan solos).
- [ ] Registrar un contacto (canal, quién inició, resultado, fecha, notas, próxima acción con fecha); aparece arriba en el historial con su autor.
- [ ] La fecha del contacto no puede ser futura; una próxima acción exige descripción y no puede ser anterior al contacto.
- [ ] Corregir: solo su autor (o quien gestiona clientes); queda en Auditoría.
- [ ] Un vendedor solo ve y registra contactos de sus clientes; un usuario sin `interactions.manage` consulta pero no registra.
- [ ] «El cliente pidió no ser contactado»: pide motivo, muestra el aviso, bloquea contactos salientes y próximas acciones, permite registrar los que inicia el cliente y se puede retirar.

### Agenda de seguimiento (`/agenda`, Fase 5 paso 2)
- [ ] El menú «Agenda» aparece solo con `interactions.manage`; la pantalla funciona en móvil sin desplazamiento horizontal de la página.
- [ ] Las acciones con fecha pasada salen en «vencidas» (con los días de atraso en texto); las del plazo elegido, en «próximas»; las cumplidas no aparecen.
- [ ] «Marcar cumplida» la quita de la agenda (y en la ficha del cliente aparece como cumplida).
- [ ] Un vendedor ve solo lo de sus clientes. Un supervisor ve los clientes de los vendedores donde él figura como supervisor (revisa el catálogo de Vendedores) y lo que registró.
- [ ] «Clientes por reactivar»: solo inactivos y perdidos, sin contacto en el plazo elegido, y sin los marcados «no contactar».

### Cotizaciones (`/cotizaciones`, Fase 5 paso 3)
- [ ] Antes de probar adjuntos: reconstruir la imagen (`docker compose build backend queue`) para que PHP admita archivos grandes.
- [ ] Crear una cotización desde la ficha del cliente («Nueva cotización») y desde el listado (buscando al cliente); el número es consecutivo (`COT-000001`…) por compañía.
- [ ] Emitir exige importe > 0 y fecha de vencimiento; rechazar exige motivo; el historial de estados muestra cada cambio con usuario y fecha.
- [ ] Una cotización emitida/enviada/en seguimiento con fecha pasada se ve como «Vencida»; ampliando la fecha vuelve a estar vigente.
- [ ] «Aceptada» no se cuenta como venta; vincular una factura del cliente la pasa a «Convertida en venta». Solo se ofrecen facturas del cliente, posteriores a la emisión y no vinculadas a otra.
- [ ] Adjunto: subir un PDF y una foto (se reemplaza), descargar, quitar; uno de más de 15 MB o de otro tipo se rechaza con un mensaje claro.
- [ ] Un vendedor solo ve las cotizaciones de sus clientes; sin `interactions.manage` solo consulta.
- [ ] Un cliente «no contactar» no permite marcar la cotización como enviada.

### Indicadores de seguimiento (`/seguimiento`, Fase 5 paso 4)
- [ ] Contactos del periodo = los registrados en las fichas en esas fechas (el día final incluido); «efectivos» no incluye sin respuesta ni contacto incorrecto.
- [ ] Cumplimiento: una acción cerrada el mismo día programado cuenta «a tiempo»; las que aún no vencen no bajan el porcentaje.
- [ ] Clientes nuevos y recuperados: contrasta 2 o 3 clientes con sus fechas de compra en la ficha.
- [ ] Conversión: cuenta las cotizaciones **emitidas** en el periodo; solo las que tienen factura vinculada son «convertidas»; las abiertas salen aparte.
- [ ] Un vendedor solo ve cifras de sus clientes; un supervisor, las de su equipo (vendedores donde figura como supervisor) y lo que él registró.
- [ ] La pantalla se ve completa en móvil y un periodo invertido avisa sin consultar.

### Crédito: facturas y saldos (`/credito`, Fase 6 pasos 1 y 2)
- [ ] En Importaciones, el tipo «Facturas de crédito» ofrece su plantilla; un archivo con Subtotal − Descuento + Impuesto distinto del Total se rechaza señalando fila y columna.
- [ ] Cargar el mismo archivo otra vez avisa que ya fue importado; una factura ya cargada con datos distintos se rechaza (no se sobrescribe).
- [ ] Una factura de crédito cuyo documento ya existe en ventas queda enlazada; si los importes o el cliente difieren, avisa sin bloquear.
- [ ] En `/credito`, el saldo de cada factura es su total (aún sin abonos) y la antigüedad cae en el tramo correcto según su fecha (1-30, 31-60, 61-90, 91-120, más de 120 días).
- [ ] El detalle muestra los movimientos y el saldo; un vendedor solo ve las facturas donde es el vendedor.

## 4b. Productos (`/productos`)

- [ ] Tarjetas de cobertura: productos en catálogo, vendidos en 12 meses, vendidos sin catálogo y % de venta con catálogo; aviso si faltan productos.
- [ ] Búsqueda por código o nombre; filtros de compañía y de grupo/línea/categoría (cada nivel solo ofrece lo que existe bajo el superior; «Sin nivel» encuentra los vacíos).
- [ ] La venta de 12 meses de 2 o 3 productos coincide con Ventas filtrando por ese producto.
- [ ] Pestaña «Vendidos sin catálogo»: lista los productos que faltan, de mayor a menor venta; al cargarlos desde Importaciones, desaparecen de la lista.
- [ ] En Ventas, las pestañas Grupo, Línea y Categoría reflejan el catálogo cargado; lo que falta sale como «Sin clasificar».

## 5. Importaciones (`/importaciones`)

- [ ] **Clientes**: descargar la plantilla; cargar un archivo válido → vista previa, resumen (nuevos / se actualizarán / sin cambios) y confirmación.
- [ ] Columnas opcionales `Tipo`, `Vendedor`, `Facturador`: sin la columna no se toca nada; con la columna y celda vacía se quita; un nombre inexistente rechaza la fila indicando la columna.
- [ ] **Productos**: plantilla con Compañía, Sucursal, Código, Descripción o Nombre, Nivel 1, 2 y 3; el mismo código en dos sucursales no da conflicto; con datos distintos, sí.
- [ ] **Ventas**: un cliente nuevo en el archivo aparece en el listado de clientes.
- [ ] Cargar dos veces el mismo archivo avisa que ya fue importado.
- [ ] Errores con fila y columna, y descarga del CSV de errores.

## 6. Permisos y alcance (probar con otros usuarios)

- [ ] `supervisor` (Alfa: Central y Norte) no ve nada de Beta ni de otras sucursales.
- [ ] Un usuario limitado a una sola sucursal ve en la ficha solo las facturas de esa sucursal, pero los datos maestros del cliente.
- [ ] `vendedor1` (rol Vendedor vinculado a su vendedor) ve **solo los clientes asignados a él**: con los datos de demostración no tiene ninguno asignado, así que asigna uno desde la ficha como `admin` y vuelve a entrar como `vendedor1`.
- [ ] Un usuario sin `customers.manage` no ve los botones de editar, ni los enlaces a Vendedores, Facturadores y Tipos.
- [ ] Un usuario sin `sales.view` o `customers.view` es redirigido al inicio al abrir la pantalla.
- [ ] **Auditoría**: aparecen metas, exportaciones, tipos, asignaciones, vendedores, facturadores, cambios de cliente y reglas de actividad.

## 7. Contraste de cifras con un reporte validado

Hazlo con un periodo cerrado (p. ej., un mes completo) y la misma compañía y sucursal en ambos lados. Elige el reporte que ya das por correcto (el del sistema de facturación o el que usa gerencia).

| Qué comparar | Dónde ver en la aplicación | Coincide |
|---|---|---|
| Venta neta total del periodo | Ventas → indicador «Venta neta» | [ ] |
| Impuesto y total facturado | Ventas → indicadores | [ ] |
| Número de documentos | Ventas → «Documentos» | [ ] |
| Venta neta por sucursal | Ventas → ranking por sucursal | [ ] |
| Venta neta por vendedor (5 principales) | Ventas → ranking por vendedor | [ ] |
| Venta neta por categoría (3 principales) | Ventas → ranking por categoría | [ ] |
| Venta de 3 clientes (uno grande, uno pequeño, uno con muchas facturas) | Clientes → ficha, o Ventas por cliente con el mismo periodo | [ ] |
| Cumplimiento de metas de un vendedor | Metas (venta del mes ÷ meta efectiva) | [ ] |
| Unidades vendidas de un producto | Ventas → ranking por producto | [ ] |

Puntos a tener presentes al comparar:

- [ ] **Devoluciones / notas de crédito:** hoy **no se descuentan** (no hay formato definido). Si el reporte de referencia las resta, la diferencia debe coincidir con ellas.
- [ ] **Margen:** es estimado con el **costo actual**, no el histórico; no esperes igualdad con el margen contable.
- [ ] **Categoría:** es el Nivel 3 del catálogo de productos; si cargaste el catálogo, compara con ese nombre. Productos fuera del catálogo o sin Nivel 3 conservan la categoría del archivo de ventas.
- [ ] **Documentos por categoría o producto:** una factura con varias líneas cuenta en cada grupo; no se suman entre filas.
- [ ] **Redondeo:** el Excel y la pantalla pueden diferir en centavos al sumar fila a fila (se redondea por fila).
- [ ] **Fechas:** se usa la fecha de emisión de la factura; el periodo incluye el día inicial y el final.
- [ ] **Venta directa del facturador:** revisa en 3 facturas conocidas que el criterio «vendedor y facturador con el mismo nombre» coincide con lo que tú consideras venta directa.
- [ ] **Estado «nuevo»:** solo se marca si el historial cargado empieza antes de su primera compra; con historial corto habrá menos «nuevos» de los reales.

## 8. Cierre

- [ ] Lista de fallos entregada (pantalla, usuario, pasos, esperado/obtenido).
- [ ] Definido el formato de **devoluciones / notas de crédito** para poder descontarlas.
- [ ] Fases 3 y 4 aceptadas, o lista de cambios pendientes acordada.

### Crédito: abonos y notas de crédito (Fase 6, paso 3)
- [ ] Otorgar `credit.payments` a quienes cobran y `credit.notes` solo a administrador y supervisor.
- [ ] Registrar un abono repartido en dos facturas del mismo cliente: los saldos bajan y aparece en Auditoría.
- [ ] Intentar un abono mayor al saldo: se rechaza y no queda ningún movimiento.
- [ ] Registrar una nota de crédito (total = suma de lo aplicado) y repetir la misma serie/documento: se rechaza.
- [ ] Anular un abono y una nota de crédito: los saldos vuelven y el original queda marcado como «Anulado».

### Crédito: cartera y devoluciones (Fase 6, paso 4)
- [ ] «Antigüedad de cartera»: el total por tramo coincide con el saldo por cobrar de «Crédito».
- [ ] Exportar el CSV y comparar un cliente contra su ficha.
- [ ] En la ficha de un cliente con nota de crédito: «Devoluciones» muestra el subtotal; tras anular la nota, vuelve a cero.
- [ ] Un vendedor solo ve la cartera de sus facturas.

### Crédito: promesas de pago (Fase 6, paso 5)
- [ ] Otorgar `credit.promises` a quien cobra.
- [ ] Registrar una promesa en la ficha; aparece en la Agenda como pendiente.
- [ ] Registrar abonos por el monto hasta la fecha prometida: pasa a «Cumplida». Anular el abono: vuelve a pendiente.
- [ ] Pasada la fecha sin abonos suficientes: «Incumplida» en la Agenda durante 30 días.
- [ ] Cancelar una promesa con motivo: queda «Cancelada» y sale de la Agenda.

### Crédito: calificación (Fase 6, paso 6)
- [ ] Un cliente que siempre paga a tiempo: riesgo bajo. Uno con saldo de más de 120 días: riesgo alto.
- [ ] Cada factor muestra su razón y los factores «Sin datos» no cambian la puntuación.
- [ ] Revisar con el equipo de cobranza que los pesos (40/25/25/10) y los cortes (75 y 50) reflejan su criterio; ajustar en `config/credit.php`.

### Inventario: unidades e inventario inicial (Fase 7, pasos 1 a 3)
- [ ] Asignar `inventory.view`, `inventory.manage` (quien administra) e `inventory.costs` (solo administrador).
- [ ] Cargar el catálogo de productos, luego «Unidades y equivalencias» (galón como base y barril, cubeta, litro, octavo, 1/16, 1/32, 1/64) y revisar un producto en Inventario → Unidades.
- [ ] Cargar el «Inventario inicial» de una sucursal con su fecha de corte; en Existencias el saldo coincide con el conteo y no aparece la marca «Sin inventario inicial».
- [ ] Un producto contado en cubetas queda en galones; el kárdex muestra «2 Cubeta» y 10 en el saldo.
- [ ] Un usuario sin `inventory.costs` no ve ningún costo en Existencias ni en el kárdex.

### Inventario: entradas (Fase 7, paso 4)
- [ ] Cargar un documento de entrada con descuento e IVA: el costo promedio sube con el Sub Total (sin IVA) y la existencia con la cantidad convertida a la unidad base.
- [ ] Un Sub Total o Total que no cuadra se rechaza con el importe esperado en el mensaje.
- [ ] Subir otro archivo con el mismo documento: se omite; con otra cantidad: se rechaza.
- [ ] Un documento anterior a la fecha de corte de su sucursal se omite con aviso.

### Inventario: ventas y faltantes (Fase 7, paso 5)
- [ ] Con inventario inicial cargado, importar ventas posteriores: las existencias bajan la cantidad vendida y el kárdex muestra «Venta» con su documento.
- [ ] Reimportar el mismo día de ventas o correr `inventory:sync-sales` otra vez no cambia las existencias.
- [ ] Una venta mayor a la existencia deja la existencia en cero y aparece en Faltantes; al cargar la entrada y pulsar Conciliar, se descuenta y desaparece.
- [ ] Ventas anteriores a la fecha de corte no descuentan.

### Inventario: ajustes, devoluciones y traslados (Fase 7, paso 6)
- [ ] Un ajuste de salida mayor a la existencia se rechaza; uno válido baja la existencia al costo promedio y muestra su motivo y quién lo hizo.
- [ ] Un traslado baja la sucursal de origen y sube la de destino al mismo valor; el kárdex de ambas lo muestra.
- [ ] Revertir un traslado deja ambas sucursales como estaban; si el destino ya vendió, se rechaza.
- [ ] Revertir un documento de entrada quita todas sus líneas y permite cargar el documento corregido.

### Inventario: rotación y reposición (Fase 7, paso 7)
- [ ] Revisar con el responsable los días de reposición (15), seguridad (7) y ciclo de compra (30), y los 60 días de «sin rotación»; ajustar en `config/inventory.php`.
- [ ] Un producto que vende 1 por día con 10 en existencia aparece «Bajo el mínimo» con cobertura de 10 días y reposición sugerida hasta el máximo.
- [ ] Un producto con existencia y sin ventas en 60 días aparece «Sin rotación».
- [ ] El CSV coincide con la pantalla y solo el administrador ve el valor.

### Inteligencia comercial: base (Fase 8, paso 1)
- [ ] Asignar `insights.view` a gerencia y supervisores, y `insights.manage` solo a quien deba cambiar umbrales.
- [ ] En «Inteligencia», cada compañía muestra sus meses de historial reales y los análisis sin historial suficiente explican qué falta.
- [ ] Cambiar un parámetro (p. ej. el historial mínimo de RFM) cambia la disponibilidad y queda en Auditoría.

### Inteligencia comercial: segmentación RFM (Fase 8, paso 2)
- [ ] Recalcular una compañía con historial real: el resumen por segmento suma todos sus clientes con compras del periodo.
- [ ] Revisar con gerencia tres clientes conocidos (uno estrella, uno que se enfrió, uno nuevo): el segmento y la explicación coinciden con su criterio.
- [ ] Un cliente con una devolución grande baja su valor neto y puede cambiar de segmento.
- [ ] Un vendedor solo ve los segmentos de sus clientes; la ficha muestra el segmento y la fecha de cálculo.

### Inteligencia comercial: concentración y cohortes (Fase 8, paso 3)
- [ ] Recalcular una compañía con historial real: el total de «Clientes» coincide con la venta neta del periodo menos devoluciones.
- [ ] Revisar con gerencia a los 3 clientes principales y el porcentaje que pesan; confirmar si el 15 % es el umbral de aviso adecuado.
- [ ] En cohortes, la primera fila aparece marcada como «previos al historial» y la retención del primer mes de cada cohorte es 100 %.
- [ ] Un vendedor no ve estas cifras.
