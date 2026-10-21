# Fase 07 — Inventario, movimientos y equivalencias

## Objetivo
Controlar existencias por compañía, sucursal y producto, enlazando entradas, ventas y otros movimientos.

## Alcance
- Catálogo de productos, líneas, categorías y unidades.
- Importación de entradas mediante plantilla futura.
- Carga y conciliación del inventario inicial.
- Salidas a partir de líneas de venta.
- Devoluciones, traslados y ajustes autorizados.
- Equivalencias específicas por producto.
- Existencias y reportes de rotación.

## Reglas
- Convertir cantidades a una unidad base definida por producto.
- Cada equivalencia tiene unidad origen/destino y factor.
- No asumir contenido universal de cajas/paquetes.
- Evitar doble salida al reimportar ventas.
- No calcular existencias desde movimientos parciales sin saldo inicial conciliado.
- Ajustes con motivo y auditoría; devoluciones revierten movimientos sin borrar historia.

## Aceptación
Existencias reconciliables por producto/sucursal/unidad; equivalencias comprobables; reimportaciones sin movimientos duplicados.

## Preguntas abiertas
Ver `15_PREGUNTAS_FASE_07_INVENTARIO.md` (decisiones #11, #14 y #15). Las respuestas definen las plantillas y la fecha de corte.

## Pasos propuestos
Los pasos 1 y 2 no dependen de las plantillas y pueden avanzar ya; del 3 en adelante necesitan las respuestas.

1. **Unidades, equivalencias y movimientos (sin plantillas).** Unidad base por producto; equivalencias con factor por producto (origen, destino, factor exacto) y conversión a la unidad base con aritmética entera; libro de movimientos inmutable (entrada, salida, traslado, ajuste, reversa enlazada) con clave de origen única para que reimportar no duplique. Permiso nuevo `inventory.view`.
2. **Consulta de existencias y kárdex.** Existencia por compañía, sucursal y producto (suma de movimientos desde la fecha de corte), kárdex del producto con saldo corrido y filtros; las existencias solo se muestran donde hay inventario inicial conciliado.
3. **Importaciones: inventario inicial y catálogo de unidades/equivalencias.** Plantillas con validación, vista previa y conciliación (dos tipos nuevos de importación) y fecha de corte por sucursal.
4. **Importación de entradas.** Plantilla de entradas con costo, idempotente por documento y serie.
5. **Salidas desde ventas.** Cada línea de venta genera su salida de forma idempotente (también al importar ventas anteriores a la fecha de corte: se ignoran), con aviso de saldos negativos para conciliar.
6. **Devoluciones, traslados y ajustes.** Captura en pantalla con motivo, autorización y auditoría; reversas sin borrar historia.
7. **Reportes de rotación y cobertura.** Rotación, cobertura en días, productos sin movimiento y mínimos/máximos con alertas, según las respuestas.

## Respuestas recibidas (responsable)
- **Unidad de referencia: galón.** Hay equivalencias desde barril, cubeta, litro, octavo, 1/16, 1/32 y 1/64. Habrá otras unidades sin equivalencia (cada producto conserva su propia unidad base).
- **Inventario inicial** (Excel): Fecha Corte, Código Producto, Unidad de Medida, Cantidad, Sucursal, Compañía.
- **Entradas** (Excel): Fecha, Documento, Proveedor, Sucursal, Compañía, Código Producto, Unidad Medida, Costo, Descuento, Sub Total, Iva, Total, Observaciones. *(No trae columna de cantidad: pendiente.)*
- **No se permiten existencias negativas.** Valuación por **costo promedio ponderado**. **Los costos los ve el administrador.**
- Rotación, máximos y mínimos: los propone el sistema (ver propuesta).
- No hay inventario real: se hará una **toma de prueba** (datos de demostración) para validar y presentar la demo.

## Decisiones tomadas (con el responsable)
- Se agrega la columna **Cantidad** a la plantilla de entradas (`Costo` = costo unitario en la unidad indicada; `Sub Total` = cantidad × costo − descuento).
- El inventario inicial lleva columna **Costo**.
- **Faltantes:** traslados, ajustes y devoluciones a proveedor se rechazan si dejarían saldo negativo. Una venta sin existencia suficiente se importa igual (ya ocurrió): la existencia se muestra en cero y la venta queda en una lista de «faltantes por conciliar».
- **Rotación, mínimos y máximos** (propuesta aceptada): demanda diaria = salidas de 90 días ÷ 90; rotación = salidas del periodo ÷ existencia promedio; cobertura = existencia ÷ demanda diaria; sin rotación = 60 días sin salidas; mínimo = demanda × 22 días (15 de reposición + 7 de seguridad); máximo = demanda × 52 días; todo configurable.
- **Las `Und.Vendidas` ya vienen en la unidad base del producto** (fracciones del galón ya convertidas: 1/4 = 0,25, 3/8 = 0,375…); los productos sin equivalencia se venden en su propia unidad base.

## Paso 1 — Unidades, equivalencias y libro de movimientos (hecho)
- Catálogo de **unidades** (galón, barril, cubeta, litro, octavo, 1/16, 1/32, 1/64 y unidad; se agregan más y se desactivan, no se borran). Cada producto tiene su **unidad base** y sus **equivalencias** (1 unidad = N unidades base; factor decimal de hasta 6 decimales guardado como fracción exacta). Pantalla «Unidades» (`/inventario/unidades`).
- Cantidades con **8 decimales como enteros** (sin float): una fracción como 0,00097656 se conserva exacta; más de 8 decimales se redondean. Máximo 10.000.000 por movimiento.
- **Libro de movimientos inmutable** (`inventory_movements`): inicial, entrada, devolución de cliente, traslado, ajuste, venta, devolución a proveedor y reversa. Cantidad y valor con signo, siempre en la unidad base (se guarda también lo capturado y su unidad).
- **El saldo (cantidad y valor) es la suma de los movimientos**: lo escribe solo el libro, en la misma transacción y con el producto bloqueado.
- **Sin existencias negativas:** una salida mayor al saldo se rechaza. Las salidas se valoran a **costo promedio ponderado**; una salida total deja el valor exactamente en cero.
- **Idempotencia:** con una clave de origen, repetir el mismo movimiento no lo duplica.
- **Reversas** enlazadas (no se borra historia), con motivo, una sola vez, y rechazadas si dejarían existencias negativas.
- Permisos nuevos (no se dan solos): `inventory.view` (consultar), `inventory.manage` (unidades, equivalencias y movimientos), `inventory.costs` (ver costos y valuación; solo administrador).
- Un producto sin unidad base no admite movimientos; la unidad base no se cambia cuando ya tiene movimientos.

## Paso 2 — Existencias, kárdex y toma de prueba (hecho)
- **Existencias** (`/inventario`, permiso `inventory.view`): saldo por producto y sucursal en la unidad base, con filtros de compañía, sucursal, texto y estado (con existencias, agotados, todos). Respeta el ámbito de compañías y sucursales del usuario.
- **Kárdex** (`/inventario/kardex`): movimientos de un producto en una sucursal con saldo corrido, lo capturado en su unidad original (p. ej. «2 cubetas» = 10 galones), reversas marcadas y rango de fechas con saldo inicial (hasta 500 movimientos por consulta).
- **Costos solo con `inventory.costs`** (el administrador): sin ese permiso la respuesta del servidor no incluye ningún importe, no solo la pantalla. Con él se ven costo promedio, valor de cada movimiento, valor del saldo y valor total del inventario.
- **Sin inventario inicial no es una existencia real:** las sucursales sin inventario inicial muestran sus saldos con la marca «Sin inventario inicial» y un aviso, porque se calculan con movimientos parciales.
- **Toma de prueba para la demo:** `docker compose exec -u $(id -u) backend php artisan db:seed --class=DemoInventorySeeder` (después de `DemoSalesSeeder`; tarda unos minutos). Crea los productos de la demo con su unidad base (galón para lubricantes y limpieza, con barril, cubeta, litro, octavo, 1/16, 1/32 y 1/64; unidad para el resto), un inventario inicial el día anterior a la primera venta, entradas al inicio de cada mes (los líquidos en cubetas, convertidas a galones, con costos que varían ±5 %) y las salidas de cada línea vendida. No deja existencias negativas, marca todo con la referencia «DEMO», es repetible y no corre en producción.
- Las salidas de la demo usan la misma clave de origen que usará el paso 5 para las ventas reales (`sale:<id de la línea>`), así que no se duplican.
