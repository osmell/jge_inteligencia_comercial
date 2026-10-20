# Fase 04 — Cliente 360°

## Objetivo
Centralizar datos maestros, compras, productos, asignaciones, actividades y alertas de cada cliente.

## Alcance
- Catálogo editable de tipos de cliente.
- Datos principales, contactos y búsqueda.
- Historial de ventas y detalle de productos.
- Resumen de compras, frecuencia, ticket, última compra y líneas principales.
- Asignación de varios vendedores por cliente, con responsable principal opcional.
- Clasificación comercial independiente del tipo de cliente.
- Importación futura de clientes y alta/edición manual.
- Auditoría de cambios.

## Reglas
- El código de origen identifica al cliente; resolver duplicados mediante proceso explícito.
- No crear clientes nuevos automáticamente por diferencias de escritura.
- Indicar si el historial disponible es parcial.
- Aplicar permisos a la ficha y sus datos.
- Potencial comercial y scoring de pago son conceptos diferentes.

## Aceptación
Desde la ficha se navega a compras, productos, actividades y asignaciones; los totales coinciden con ventas validadas.

## Avance

### Paso 1 — Ficha de solo lectura desde las ventas
La ficha se construyó primero solo con las ventas importadas; el paso 2 la enlaza con los datos maestros (el cliente se identifica por **compañía + código**, no solo por código).

- **Permiso:** `customers.view` («Clientes»); el ámbito de compañía/sucursal del usuario se aplica por los global scopes de ventas (quien ve solo una sucursal ve solo esas facturas, igual que en el dashboard).
- **API:** `GET /customers` (búsqueda por código o nombre, orden por última compra, venta neta, documentos o nombre; 20 por página; `%` y `_` se buscan literalmente), `GET /customers/profile?code=`, `GET /customers/purchases?code=&page=`, `GET /customers/document?code=&document_id=`. El código va en la consulta porque puede traer caracteres especiales.
- **Ficha:** venta neta total, documentos, ticket promedio, última compra (y hace cuántos días), frecuencia (promedio de días entre días con compra), margen estimado con costo actual, venta de los últimos 12 meses (con ceros), productos, categorías, vendedores y sucursales principales, e historial de compras con el detalle de productos de cada factura.
- **Historial parcial:** la ficha siempre indica desde qué fecha hay ventas en el sistema; lo anterior no aparece.
- **Sin umbrales:** la ficha muestra frecuencia y días desde la última compra, pero no clasifica al cliente como inactivo, nuevo, recuperado o perdido: esas reglas están por definir (decisiones #17 y #18).
- **Pantallas:** Inteligencia → Clientes (`/clientes`) y `/clientes/ficha?codigo=`.
- **Verificación:** pruebas de búsqueda, paginación, totales, frecuencia, variantes de nombre, serie mensual, alcance y permisos (backend 193, frontend 61); en PostgreSQL con datos demo, neto y documentos del primer cliente coinciden con SQL directo (484 608,74 y 514), perfil en ~60 ms.

### Paso 2 — Datos maestros e importación de clientes
Respuestas del responsable (`13_PREGUNTAS_FASE_04_CLIENTES.md`): el código de cliente es **único por compañía** (y no hay un mismo código con nombres distintos); habrá un archivo maestro con compañía, sucursal, código (entero), nombre, teléfono, correo, WhatsApp, dirección, nombre comercial y nombre de contacto, que inserta clientes nuevos y actualiza los existentes.

- **Tabla `customers`** (compañía + código único; sucursal, nombre, nombre comercial, contacto, teléfono, WhatsApp, correo, dirección, origen `import` o `sales`). Migración con relleno desde las ventas existentes (en el entorno de demo: 150 clientes = 150 pares compañía + código de ventas).
- **Importación «Clientes»** (Importaciones → tipo Clientes, con plantilla): usa el marco de importación existente (vista previa, errores por fila y columna, confirmar o descartar, auditoría). Reglas: código entero (se conservan ceros iniciales si venía como texto; acepta el número de Excel), correo y teléfonos con formato válido, compañía y sucursal deben existir y estar activas, solo se importan compañías a las que el usuario tiene acceso completo. Dos filas con el mismo cliente y datos distintos en el archivo se rechazan; idénticas, solo advierten. El archivo es la fuente: una celda vacía **borra** ese dato (el nombre es obligatorio). Resumen: nuevos, se actualizarán, sin cambios.
- **Ventas → clientes:** al importar ventas, un código que no existe en esa compañía se da de alta (origen `sales`, con el nombre de la factura y la sucursal de su primera venta). Un cliente existente no se modifica: manda el maestro.
- **Ficha y listado:** el listado incluye clientes sin compras; la ficha muestra los datos maestros, indica si el cliente solo se creó desde ventas y agrega quién facturó cada compra. Se elimina el aviso de nombres distintos por código.
- **Visibilidad:** los datos maestros se limitan por compañía (no por sucursal: un cliente puede comprar en varias); las cifras de venta de la ficha sí respetan las sucursales del usuario. La visibilidad por rol llega con las asignaciones.

### Paso 3 — Tipos de cliente y clasificación ABC
- **Tipos de cliente:** catálogo permanente del grupo (`customer_types`): se crean, se renombran y se **desactivan**, no se borran. Un cliente tiene un tipo o ninguno; solo se pueden asignar tipos activos. Cada alta, cambio y asignación se audita (`customer_types.created|updated`, `customers.type_updated`).
- **Permiso nuevo `customers.manage`** («Gestión de clientes»): crear/editar tipos y asignarlos. Ver el catálogo y filtrar solo requiere `customers.view`. El administrador lo recibe; los demás roles se lo otorgan desde «Roles y permisos» (10 permisos en total).
- **Clasificación comercial automática ABC (Pareto):** por venta neta de los **últimos 12 meses** de la compañía; A hasta el 80 % acumulado, B hasta el 95 %, C el resto; sin ventas (o neto ≤ 0) en 12 meses no tiene clase. Se calcula sobre toda la compañía, sin importar las sucursales del usuario, para que la letra sea la misma para todos. La clasificación manual por potencial queda pendiente de que el responsable la confirme.
- **API:** `GET /customers/types`, `POST /customers/types`, `PUT /customers/types/{id}`, `PUT /customers/type` (asigna o quita el tipo de un cliente); `GET /customers` acepta `type_id` y devuelve `type` y `abc`; la ficha devuelve ambos.
- **Pantallas:** `/clientes/tipos` (catálogo), columnas y filtro de tipo en el listado, y cambio de tipo en la ficha.
- **Pendiente:** asignar el tipo desde el Excel de clientes.

### Paso 4 — Estado de actividad del cliente
- **Estados:** activo, inactivo, nuevo, recuperado, perdido y sin compras. Plazos configurables (por defecto **30** días para inactivo y **90** para perdido; el segundo debe ser mayor que el primero).
- **Reglas:** activo = última compra hace ≤ plazo de inactividad; inactivo = más de ese plazo y hasta el plazo de perdido; perdido = más allá; nuevo = primera compra dentro del plazo (solo si el historial de la compañía empieza antes de esa compra: si no, no se sabe si es la primera); recuperado = compró dentro del plazo tras pasar más de ese plazo sin comprar.
- **Alcance:** se calcula sobre toda la compañía (sin importar las sucursales del usuario), igual que el ABC, para que el estado sea el mismo para todos.
- **Configuración:** tabla `app_settings` (clave → valor); `GET /customers/activity-rules` (`customers.view`) y `PUT` (`customers.manage`, auditado como `customers.activity_rules_updated`); pantalla en `/clientes/tipos`.
- **API/pantallas:** `GET /customers?status=` filtra; el listado y la ficha muestran el estado.
- **Verificación:** pruebas de cada estado y de los plazos; en PostgreSQL con datos demo se clasifican los 150 clientes en 62 ms. La memoria de pruebas de PHP se subió a 512 M (la suite ya agotaba 128 M).

### Paso 5 — Vendedores por defecto, asignaciones y supervisor
- **Regla de venta directa del facturador (confirmada):** cuando en una venta el vendedor y el facturador (operador) tienen **el mismo nombre**, la venta es del facturador y cuenta en sus estadísticas; en cualquier otro caso es del vendedor. Los operadores son los facturadores. Quien carga la información es el supervisor de ventas.
- **Catálogo de vendedores:** nuevos campos `branch_id`, `is_default` («Tienda por defecto», un solo vendedor así por sucursal) y `supervisor_id`. Cada sucursal tiene su vendedor «Tienda <sucursal>»: se creó para las existentes (migración) y se crea al dar de alta una sucursal; si ya existía un vendedor con ese nombre se reutiliza.
- **Asignación por cliente:** `customers.seller_id` y `customers.operator_id`. Sin vendedor asignado, el cliente se atribuye a la «Tienda» de su sucursal (se calcula al consultar, no se guarda). El **supervisor** del cliente es el del vendedor.
- **Permisos y auditoría:** consultar usa `customers.view`; asignar y editar el catálogo exige `customers.manage` (eventos `customers.assignment_updated` y `sellers.updated`). El vendedor y el facturador deben ser de la misma compañía y estar activos; un supervisor debe tener rol Supervisor de Ventas.
- **Visibilidad por rol:** un usuario con rol Vendedor, vinculado a un vendedor (`sellers.user_id`), solo ve los clientes asignados a él.
- **Pantallas:** `/clientes/vendedores` (sucursal, tienda por defecto y supervisor por vendedor), ficha con vendedor/facturador/supervisor y selectores de asignación, y columna Vendedor en el listado.
- **Pendiente:** el vínculo de un usuario con su vendedor aún no tiene pantalla (`sellers.user_id`); también la importación de clientes no asigna vendedor.

### Paso 6 — Reporte de ventas por cliente
- **Qué muestra:** por cliente con ventas en el periodo: venta neta, documentos, última compra, vendedor asignado (o la «Tienda» por defecto) y su supervisor, **quién vendió** y **quién facturó** (con el monto de cada uno) y la **venta directa del facturador** (vendedor y facturador con el mismo nombre). En pantalla se ven las 3 personas principales; el archivo las incluye todas.
- **Filtros:** periodo (máximo 1096 días), compañía y sucursal; respeta el ámbito del usuario y, para el rol Vendedor, solo sus clientes. Pantalla `/clientes/reporte` (enlace «Ventas por cliente» en Clientes).
- **Exportación:** CSV y Excel (hoja de datos + hoja de notas con periodo, filtros y reglas), hasta 5 000 clientes, auditada como `customers.sales_report_exported`; textos que parecen fórmulas se neutralizan.
- **API:** `GET /customers/sales-report` y `GET /customers/sales-report/export?format=csv|xlsx` (permiso `customers.view`).
- **Devoluciones:** el responsable confirmó que restan a la venta del cliente, pero sin formato de notas de crédito aún no se descuentan; la pantalla y el Excel lo avisan.
- **Verificación:** en PostgreSQL con datos demo, 150 clientes en 128 ms; la suma del año coincide con SQL directo (diferencia de 0,09 por redondear fila a fila).

### Paso 7 — Importación de productos
- **Tabla `products`** (compañía + código único; nombre, Nivel 1 = grupo general, Nivel 2 = línea, Nivel 3 = categoría). El código es un entero único por compañía.
- **Importación «Productos»** (Importaciones → tipo Productos, con plantilla): columnas Compañía, Sucursal, Código, Descripción o Nombre, Nivel 1, Nivel 2, Nivel 3 (los niveles son opcionales; una celda vacía borra ese dato). Inserta los nuevos y actualiza los existentes, con vista previa, errores por fila y columna, confirmación y auditoría, igual que clientes.
- **Sucursal:** indica dónde se generó la factura y solo se valida (debe existir y ser de la compañía). Un mismo producto en varias sucursales de la compañía no es conflicto si el resto de los datos coincide; con datos distintos, el lote se rechaza.
- **Pendiente:** el catálogo aún no alimenta los reportes (la categoría de las ventas sigue viniendo del archivo de ventas) ni tiene pantalla de consulta. Falta decidir si el desglose por categoría pasa a usar el Nivel 3.

### Paso 8 — Mantenimiento de clientes, vendedores y facturadores
- **Datos generales del cliente:** el botón «Editar datos» de la ficha (permiso `customers.manage`) cambia nombre, nombre comercial, contacto, teléfono, WhatsApp, correo, dirección y sucursal, con las mismas validaciones que la importación. El código no se cambia. Un cliente creado desde ventas pasa a origen «manual»; cada cambio se audita (`customers.updated`). Una importación de clientes posterior vuelve a escribir esos datos con lo que traiga el archivo.
- **Vendedores** (`/clientes/vendedores`): alta, renombrar, activar/desactivar, sucursal, «Tienda por defecto», supervisor y **usuario vinculado** (usuarios con rol Vendedor; de ahí sale la visibilidad del rol Vendedor). El nombre es único por compañía sin distinguir mayúsculas ni espacios de más. Auditado (`sellers.created|updated`).
- **Facturadores** (`/clientes/facturadores`): alta, renombrar y activar/desactivar (`operators.created|updated`). Un facturador inactivo ya no se ofrece al asignar clientes.
- **Aviso de enlace por nombre:** las ventas se enlazan a vendedores y facturadores por su nombre al importar; renombrar a alguien exige que el archivo de ventas traiga el nombre nuevo o se creará otro registro. La pantalla lo advierte.
- **API:** `PUT /customers/data`, `POST /customers/sellers`, `PUT /customers/sellers/{id}` (ahora con nombre, estado y usuario), `GET /customers/seller-users`, `POST /customers/operators`, `PUT /customers/operators/{id}`. Alta y cambios exigen acceso completo a la compañía.

### Respuestas recibidas (ronda 2)
- Productos: el archivo usa los encabezados **Nivel 1, Nivel 2, Nivel 3**; la sucursal es donde se generó la factura y un código puede venderse en varias sucursales de la misma compañía.
- Atribución: la venta es del vendedor aunque el facturador emita la factura; si el facturador vende directamente, es del facturador y cuenta en sus estadísticas.
- El supervisor del cliente es el supervisor de su vendedor. Operadores = facturadores. Quien carga la información es el supervisor de ventas.
- Las devoluciones restan a la venta del cliente.

### Siguiente
Lo pedido en las respuestas ya está implementado. Quedan: asignar tipo y vendedor desde el Excel de clientes, descontar devoluciones cuando exista su formato, que el catálogo de productos alimente los reportes (Nivel 3 como categoría) y, sobre todo, contrastar los totales con un reporte de ventas validado y revisar las pantallas en un navegador (incluido móvil).
