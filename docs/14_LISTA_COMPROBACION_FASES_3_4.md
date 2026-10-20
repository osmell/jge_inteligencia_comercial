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
