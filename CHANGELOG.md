# Registro de cambios

Todos los cambios relevantes del proyecto, por versión. Formato basado en [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/).
Las versiones `0.N.0` corresponden a las fases del proyecto (`docs/`) y se etiquetan en el commit que las cierra. Mientras el sistema no
esté en producción la numeración es `0.x`; la `1.0.0` será la primera versión puesta en uso por el cliente.

Cómo mantenerlo: cada Pull Request que cambie algo visible para quien usa el sistema añade una línea en **Sin publicar**, bajo
*Añadido*, *Cambiado*, *Corregido* o *Seguridad*. Al cerrar una fase, esas líneas pasan a la versión nueva.

## [Sin publicar]

### Añadido
- **Conciliación de la facturación, paso 1 (`docs/30_CONCILIACION_FACTURACION.md`):** dos importaciones nuevas, «Detalle de facturación» (factura × producto con el precio establecido en la factura) y «Resumen por vendedor» (venta neta oficial), que se cargan por día acumulado sin duplicar: lo idéntico se omite, el detalle de un documento que cambió (por ejemplo, anulado después) se reemplaza con aviso y un resumen del mismo corte reemplaza al anterior. Son datos de referencia para corregir las equivalencias: no modifican las ventas importadas. Probado con los archivos reales de agosto de 2026 (16 635 líneas, 4 601 documentos, sin errores).
- **Explicación de los datos («i»), paso 4 (último):** la «i» con su explicación en Clientes (lista, reporte de ventas por cliente y ficha: indicadores, estados y clasificación ABC), Productos, Vendedores y Facturadores, Agenda, Importaciones (todas las tarjetas del detalle de cada tipo de archivo), Administración (usuarios, auditoría, automatizaciones, correo y webhook) y la calificación de crédito. Regla para pantallas nuevas: toda columna o indicador calculado, de estado o de significado no evidente lleva `Th`/`Stat` con `info` (texto del glosario o propio, con fórmula si es calculado). La prueba de navegador abre las «i» de 32 pantallas en escritorio y celular.
- **Explicación de los datos («i»), paso 3:** la «i» con su explicación y fórmula en los cinco Reportes (columnas de vendedores, operadores, sucursales, mezcla de productos y conciliación, detalle de facturación, mezcla por sucursal y detalle por persona), en Inteligencia (segmentos RFM, concentración y cohortes, alertas, estacionalidad y venta cruzada) y en Inventario (existencias, rotación, faltantes, movimientos y kárdex). Las definiciones salen de `docs/29_REPORTES.md`, `docs/08_FASE_08_INTELIGENCIA_AVANZADA.md` y del código de los servicios. La prueba de navegador abre las «i» de 22 pantallas en escritorio y celular.
- **Explicación de los datos («i»), paso 2:** la «i» con su explicación y fórmula en Inicio (todas las tarjetas y tablas), Ventas (indicadores, desglose y rankings), Metas, Seguimiento (indicadores y tabla por usuario), Cotizaciones (lista y detalle) y Crédito (lista, antigüedad y detalle). Cada texto se verificó contra las reglas del servidor; por ejemplo, el «saldo vencido» cuenta la antigüedad desde la fecha de emisión de la factura. Prueba de navegador: cada «i» abre dentro de la ventana y no cambia el ancho de la página, en escritorio y en celular.
- **Explicación de los datos («i»), paso 1:** componente `InfoTip` (una «i» que muestra qué es el dato y, si es calculado, su fórmula; se abre con el ratón, con el teclado y con un toque, y se cierra con Escape). El globo se dibuja fuera de las tablas y de las ventanas, así que no las recorta ni cambia el tamaño de la página, y se recoloca dentro de la pantalla. Glosario central en `lib/glossary.ts`, encabezado `Th` con la «i» y tarjeta `Stat` compartida (reemplaza ocho copias iguales). Ya está en las tarjetas de «Productos». Los pasos siguientes la ponen en el resto de las pantallas.
- **Menú por usuario** (Administración, solo administradores): el administrador elige a una persona que no es administradora y marca qué opciones del menú ve. Una opción oculta desaparece del menú y de la barra de comandos, no se abre escribiendo la dirección y el servidor rechaza sus datos (403 `menu_option_hidden`) cuando se ocultaron todas las opciones que los usan; si se ocultan todas las de un grupo, el grupo desaparece. Solo quita: siempre hace falta el permiso del rol. Cada cambio queda en Auditoría.
- **Mezcla de productos** (`/reportes/productos`, quinta opción del menú Reportes): lo más facturado por producto, categoría, línea o grupo (de mayor a menor, con peso y cambio frente a la comparación) y, de cada vendedor u operador, cuántas categorías y líneas del catálogo vende (cobertura), cuánto depende de sus 5 productos principales, lo que el equipo vende y esa persona no, y qué productos estrella del equipo no vendió. Imprime, Excel, PDF y correo como los demás reportes.
- **Salidas de los reportes**: imprimir, Excel (cifras como números, nivel de cada semáforo escrito), PDF (las tablas anchas se parten en bloques) y envío por correo con adjunto. El correo exige revisar y confirmar los destinatarios, envía un correo por persona, tiene un tope por persona y hora y deja en Auditoría las direcciones.
- **Menú desplegable «Reportes»**: agrupa Vendedores, Operadores, Sucursales y Conciliación; cerrado por omisión, se abre solo dentro de sus pantallas y recuerda cómo se dejó. La barra de comandos y el encabezado usan el nombre completo de cada reporte.
- **Conciliación** (`/reportes/conciliacion`): contrasta la venta consolidada con sus sumas por compañía, sucursal, vendedor y operador, comprueba que cada documento cuadre con sus líneas, compara lo que declaraba cada archivo de ventas importado contra lo guardado y las facturas de crédito contra sus ventas; abre la lista de documentos con diferencias y el detalle de facturación de cualquier vendedor, operador o sucursal.
- **Reporte de sucursales** (`/reportes/sucursales`): resultado y crecimiento, dependencia de un vendedor y de pocos clientes, cartera de clientes, cobranza, existencias en productos (sin costos) y mezcla de categorías por sucursal, con detalle de facturación.
- **Reporte de operadores** (`/reportes/operadores`): producción y control de captura de quienes facturan (descuentos altos, líneas sin precio, documentos atípicos y negativos, venta directa), con detalle de facturas y de a quién y cuándo factura cada operador. El cálculo de cifras de venta, el contraste y el listado de documentos son ahora piezas comunes a todos los reportes de evaluación.
- **Reporte de vendedores** (`/reportes/vendedores`, `docs/29_REPORTES.md`): venta, crecimiento, cumplimiento de meta, disciplina de precio, concentración, cartera de clientes, cobranza y seguimiento de cada vendedor, con semáforos, contraste con la venta consolidada y detalle de facturación por vendedor.
- **Reportes de evaluación (base)**: permiso `reports.view` (administrador, gerente general y supervisor de ventas), filtros disponibles por compañía (`GET /reports/context`), periodo con comparación (anterior, mismo periodo del año pasado o personalizado), semáforos con umbrales por omisión en `config/reports.php` y auditoría de consulta, exportación y envío por correo (con destinatarios).
- **Integración continua** en GitHub Actions (estilo, análisis estático, pruebas, tipos, lint y build), plantilla de Pull Request, Dependabot mensual y `CONTRIBUTING.md`.
- **Pruebas unitarias** (`tests/Unit`) de la aritmética de cantidades e importes, antigüedad de cartera, RFM, ventana de 24 h, reglas de importación y reglas de arquitectura.
- **Policies** de autorización por registro (conversaciones, cotizaciones, contactos, clientes, vendedores, facturadores, respuestas rápidas).
- **Puertos** (`app/Contracts`) para WhatsApp y webhooks, con sus integraciones.
- **Revisión de seguridad** (`docs/28_SEGURIDAD.md`): matriz de acceso sobre las 192 rutas, pruebas de aislamiento entre compañías y vendedores, escaneo dinámico con OWASP ZAP (0 fallos), cabeceras de seguridad, límites de peticiones, defensa contra SSRF y 56 pruebas nuevas.
- **Contrato de la API generado** (OpenAPI con Scramble en `backend/openapi.json`, documentación en `/docs/api` para administración y tipos de TypeScript generados) con comprobaciones automáticas de deriva; las cotizaciones usan `Resource` y tipos derivados.
- **Pruebas de extremo a extremo** con Playwright (`e2e/`): 7 flujos críticos en un navegador real, ejecutados cada noche y a demanda en GitHub.
- **Hooks del frontend** (`useFilters`, `useSubmit`, `useFormValues`, `useExport`, `useDebounced`, `useFitRows`) que reemplazan filas repetidas de `useState`.
- **Identificador de petición** (`X-Request-Id`) en cada respuesta y en cada registro, canal de registros `json`, registro estructurado de trabajos en cola fallidos y comando `ops:status` para monitoreo.
- Documentación para el equipo: análisis del código y guía SOLID (`docs/24`), propuesta de Policies (`docs/25`), operación (`docs/26`) y decisiones de arquitectura (`docs/adr`).
- `php artisan data:reset`: deja la base lista para información real (conserva compañías, sucursales, vendedores, facturadores, productos, cuentas y configuración). Simulación con `--dry-run`, confirmación escribiendo el nombre de la base, una sola transacción.
- **La suite de pruebas se ejecuta también en PostgreSQL** (`composer test:pgsql` y un trabajo propio en la CI), con una guarda que impide que toque una base que no termine en `_test`.
- **Revisión de seguridad del apartado de Reportes** (`docs/28_SEGURIDAD.md`) y `ADR-015`.

### Cambiado
- **Menú reorganizado en seis grupos desplegables**: General (Inicio, Conversaciones, Agenda), Administración (Usuarios, Menú por usuario, Roles y permisos, Auditoría, Automatizaciones, Correo, WhatsApp, Webhook), Catálogos (Compañías, Sucursales, Clientes, Productos, Unidades), Inteligencia, Reportes y Datos. Todos se abren con un botón y recuerdan cómo los dejó cada persona; solo General empieza abierto, y cada grupo se abre solo al estar dentro de una de sus pantallas. «Menú por usuario» sigue los mismos grupos.
- Todo el backend usa `declare(strict_types=1)`; PHPStan sube del nivel 5 al **8** sin *baseline*.
- Las consultas y transacciones salieron de los controladores hacia servicios; el dashboard, las estadísticas de ventas, la ficha de clientes y los reportes se dividieron en clases por responsabilidad. Sin cambios de comportamiento.
- Mensaje de rechazo único al escribir o agregar notas en una conversación ajena.

### Corregido
- **Mezcla de productos**: la ventana del detalle de un vendedor u operador es más ancha (hasta 72 rem) para leer completas las descripciones de los productos.
- **Pantallas con filtros mostraban datos viejos**: si se cambiaba un filtro rápido, una respuesta lenta anterior podía llegar después y reemplazar a la nueva. Ahora toda carga descarta las respuestas superadas.
- **Pruebas que dependían de la fecha de hoy**: dos pruebas de importación de inventario usaban una fecha «futura» fija que ya pasó.
- **Reportes desbordaban la pantalla**: el texto oculto de los semáforos (para lectores de pantalla) escapaba del recorte de las tablas y ensanchaba y alargaba toda la página, con barras de desplazamiento de la ventana en ambos sentidos. Ahora las tablas se desplazan dentro de su tarjeta (encabezado fijo, barra horizontal a la vista, celdas compactas) y los reportes aprovechan todo el ancho disponible. Una prueba de navegador (`11-reportes-sin-desborde`) lo vigila en 1366×768 y 1920×1080.
- **Enviar un mensaje desde Conversaciones fallaba en PostgreSQL**: el tipo de mensaje `conversation` (12 caracteres) no cabía en su columna (10). Se amplió; hay que ejecutar `php artisan migrate --force`.
- Los archivos descargados desde la interfaz (reportes, exportaciones de ventas…) se guardaban con un nombre genérico: el navegador no podía leer el encabezado con el nombre. Se expone en CORS.
- `company_id` de la URL se pasaba como texto a los servicios de crédito.
- La descarga CSV ante un fallo al abrir la salida seguía con un recurso inválido; ahora falla con un mensaje claro.
- El listado de facturas de un cliente ejecutaba consultas cuyo resultado no usaba.
- Un mes de metas con formato inválido devolvía `null` en silencio.

### Seguridad
- **PDF de reportes con tablas largas**: se recorta a 100 filas por tabla (con aviso) porque con unas 300 filas Dompdf agotaba la memoria de PHP; Excel, correo y pantalla traen todas.
- **PHP2Shell (CVE-2026-109618 y CVE-2026-109619, ejecución remota sin autenticación)**: la imagen del backend se reconstruyó con PHP 8.5.12 y una prueba (`PhpVersionTest`) hace fallar la suite y la CI si PHP es anterior a la versión corregida. En producción hay que actualizar PHP y reiniciar los procesos.
- **Época de sesión**: cerrar sesión, cambiar o restablecer la clave y desactivar una cuenta invalidan todas las sesiones anteriores de la persona, incluso una que reviva por una petición en curso. Cerrar sesión en un equipo cierra todos los de esa persona. Una respuesta 401 devuelve a la pantalla de ingreso.
- **Cambio de clave obligatorio**: la clave que fija administración (alta o restablecimiento) es temporal; hasta cambiarla, la API solo permite ver el perfil, cambiar la clave y salir. Cualquier persona puede cambiar su clave (pide la actual, con límite de intentos y en Auditoría).
- Un vendedor ya no puede cancelar promesas de pago de clientes de otro vendedor.
- Límite general de peticiones y límite de intentos de inicio de sesión por dirección; cabeceras de seguridad; sin `X-Powered-By` ni página de bienvenida.
- Las cuentas desactivadas dejan de ver `/auth/me` y de recibir tiempo real aunque conserven la sesión.
- Descarga de archivos de WhatsApp solo desde dominios de Meta; destino del webhook sin acceso a la red interna.
- Cookie de sesión `Secure` por omisión en producción; auditoría de dependencias que bloquea la CI.

## [0.12.0] — 2026-10-21 · Fase 12: barra de comandos
### Añadido
- Barra de comandos en el encabezado (tecla `/`) para ir a cualquier pantalla permitida, en lugar de los «Accesos rápidos» del dashboard.
- Registro de ajustes de la revisión (`docs/23`) con estado de aprobación.
### Cambiado
- Sucursales: se puede mover una sucursal a otra compañía si no tiene datos relacionados; si los tiene, se indica cuáles lo impiden.
- Unidades y equivalencias: la edición del producto se hace en una ventana modal y la lista se pagina según el alto de la pantalla.

## [0.11.0] — 2026-10-21 · Fase 11: conversaciones de WhatsApp
### Añadido
- Ventana de conversaciones tipo WhatsApp Web (lista, mensajes, citas, adjuntos, plantillas fuera de la ventana de 24 h).
- Recepción de mensajes por el webhook de Meta sin duplicados, con bandeja «sin asignar» y descarga de archivos en cola.
- Envío de mensajes y archivos con reintentos; validación de archivos por contenido.
- Tiempo real con Laravel Reverb y avisos de mensaje nuevo; insignia en el menú.
- Supervisión: asignar números desconocidos, etiquetas, respuestas rápidas, cotizaciones desde el chat, alerta de cliente esperando, mensaje de ausencia, registro automático de contacto y sección en el dashboard.
### Seguridad
- Credenciales de WhatsApp cifradas y nunca devueltas ni auditadas; administración y gerencia solo leen; abrir una conversación ajena queda en Auditoría; las conversaciones no se borran.

## [0.10.0] — 2026-10-20 · Fase 10: dashboard inicial por rol
### Añadido
- Dashboard de inicio por perfil (vendedor, supervisor, compañía) con ventas, metas, cartera, cotizaciones, seguimiento, inventario, recomendaciones y rankings; gráficos accesibles.

## [0.9.0] — 2026-10-20 · Fase 9: automatizaciones y notificaciones
### Añadido
- Notificaciones internas (campana) con preferencias y pausas; automatizaciones configurables; avisos operativos; resúmenes diario y semanal para gerencia.
- Correo interno, WhatsApp por plantillas con consentimiento y webhook de salida firmado, todos configurables dentro de la aplicación con credenciales cifradas.

## [0.8.0] — 2026-10-20 · Fase 8: inteligencia comercial
### Añadido
- Segmentación RFM, concentración (Pareto y HHI), cohortes, estacionalidad, venta cruzada, alertas de clientes y panel de recomendaciones.

## [0.7.0] — 2026-10-20 · Fase 7: inventario
### Añadido
- Unidades y equivalencias, libro de movimientos, existencias por sucursal, kárdex, importación de inventario inicial y entradas, salidas desde ventas, ajustes, devoluciones, traslados y reversas, rotación y alertas de reposición.

## [0.6.0] — 2026-10-20 · Fase 6: crédito y cobranzas
### Añadido
- Importación de facturas de crédito, libro de movimientos inmutable, abonos y notas de crédito, antigüedad de cartera, promesas de pago y calificación de crédito explicable.

## [0.5.0] — 2026-10-20 · Fase 5: seguimiento y cotizaciones
### Añadido
- Contactos con clientes, marca «no contactar», agenda de seguimiento, cotizaciones con estados y vínculo con la factura, e indicadores de seguimiento.

## [0.4.0] — 2026-10-19 · Fase 4: cliente 360
### Añadido
- Ficha de cliente, maestro de clientes y productos con importación, tipos, estado de actividad, clasificación ABC, vendedor «Tienda» por sucursal, asignación de vendedor y facturador, y reporte de ventas por cliente.

## [0.3.0] — 2026-10-19 · Fase 3: inteligencia de ventas
### Añadido
- Dashboard de ventas con comparación de periodos, desgloses y rankings; metas mensuales por vendedor; exportaciones a Excel, CSV y PDF (auditadas); datos de demostración.

## [0.2.0] — 2026-10-19 · Fase 2: importación y calidad de datos
### Añadido
- Marco de importación desde Excel (hasta 200 000 filas, validación en cola, errores por fila, confirmación transaccional) y adaptador de ventas con conciliación de importes.

## [0.1.0] — 2026-10-18 · Fase 1: fundación y seguridad
### Añadido
- Esqueleto Laravel + React en contenedores, autenticación por usuario, permisos por rol con alcance de compañía y sucursal, administración de compañías, sucursales y usuarios, y auditoría de cambios.
