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
Sin tabla de clientes todavía: la ficha se construye con las ventas importadas y se identifica al cliente por su **código de origen** (`customer_code`). No se crean ni se fusionan clientes por parecido de nombre.

- **Permiso:** `customers.view` («Clientes»); el ámbito de compañía/sucursal del usuario se aplica por los global scopes de ventas (quien ve solo una sucursal ve solo esas facturas, igual que en el dashboard).
- **API:** `GET /customers` (búsqueda por código o nombre, orden por última compra, venta neta, documentos o nombre; 20 por página; `%` y `_` se buscan literalmente), `GET /customers/profile?code=`, `GET /customers/purchases?code=&page=`, `GET /customers/document?code=&document_id=`. El código va en la consulta porque puede traer caracteres especiales.
- **Ficha:** venta neta total, documentos, ticket promedio, última compra (y hace cuántos días), frecuencia (promedio de días entre días con compra), margen estimado con costo actual, venta de los últimos 12 meses (con ceros), productos, categorías, vendedores y sucursales principales, e historial de compras con el detalle de productos de cada factura.
- **Historial parcial:** la ficha siempre indica desde qué fecha hay ventas en el sistema; lo anterior no aparece.
- **Datos ambiguos que se avisan, no se corrigen:** un código con varios nombres en las facturas (se muestra el más reciente y la lista de variantes) y un código con ventas en varias compañías (las cifras se suman y se indican; se puede filtrar por compañía).
- **Sin umbrales:** la ficha muestra frecuencia y días desde la última compra, pero no clasifica al cliente como inactivo, nuevo, recuperado o perdido: esas reglas están por definir (decisiones #17 y #18).
- **Pantallas:** Inteligencia → Clientes (`/clientes`) y `/clientes/ficha?codigo=`.
- **Verificación:** pruebas de búsqueda, paginación, totales, frecuencia, variantes de nombre, serie mensual, alcance y permisos (backend 193, frontend 61); en PostgreSQL con datos demo, neto y documentos del primer cliente coinciden con SQL directo (484 608,74 y 514), perfil en ~60 ms.

### Siguiente
Preguntas para el responsable (`13_PREGUNTAS_FASE_04_CLIENTES.md`). Con las respuestas: tabla de clientes (tipo, contactos, alta manual), asignación de vendedores, clasificación comercial, importación de clientes y auditoría de cambios. Falta además contrastar los totales con ventas validadas.
