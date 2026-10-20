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

### Respuestas recibidas (ronda 2)
- Productos: el archivo usa los encabezados **Nivel 1, Nivel 2, Nivel 3**; la sucursal es donde se generó la factura y un código puede venderse en varias sucursales de la misma compañía.
- Atribución: la venta es del vendedor aunque el facturador emita la factura; si el facturador vende directamente, es del facturador y cuenta en sus estadísticas.
- El supervisor del cliente es el supervisor de su vendedor. Operadores = facturadores. Quien carga la información es el supervisor de ventas.
- Las devoluciones restan a la venta del cliente.

### Siguiente
Pendiente con las demás respuestas: asignación de un vendedor y un facturador por cliente con supervisor (vendedor por defecto «Tienda» por sucursal), visibilidad por rol, estado de actividad (activo, inactivo, nuevo, recuperado, perdido; 30/90 días configurables), reporte de ventas por cliente con quién vendió y quién facturó, y carga de productos. Dudas abiertas: ver el resumen del paso en la conversación y `13_PREGUNTAS_FASE_04_CLIENTES.md`. Falta además contrastar los totales con ventas validadas.
