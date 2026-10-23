# Registro de cambios

Todos los cambios relevantes del proyecto, por versión. Formato basado en [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/).
Las versiones `0.N.0` corresponden a las fases del proyecto (`docs/`) y se etiquetan en el commit que las cierra. Mientras el sistema no
esté en producción la numeración es `0.x`; la `1.0.0` será la primera versión puesta en uso por el cliente.

Cómo mantenerlo: cada Pull Request que cambie algo visible para quien usa el sistema añade una línea en **Sin publicar**, bajo
*Añadido*, *Cambiado*, *Corregido* o *Seguridad*. Al cerrar una fase, esas líneas pasan a la versión nueva.

## [Sin publicar]

### Añadido
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

### Añadido
- `php artisan data:reset`: deja la base lista para información real (conserva compañías, sucursales, vendedores, facturadores, productos, cuentas y configuración). Simulación con `--dry-run`, confirmación escribiendo el nombre de la base, una sola transacción.

### Seguridad
- **Cambio de clave obligatorio**: la clave que fija administración (alta o restablecimiento) es temporal; hasta cambiarla, la API solo permite ver el perfil, cambiar la clave y salir. Cualquier persona puede cambiar su clave (pide la actual, con límite de intentos y en Auditoría).

### Cambiado
- Todo el backend usa `declare(strict_types=1)`; PHPStan sube del nivel 5 al **8** sin *baseline*.
- Las consultas y transacciones salieron de los controladores hacia servicios; el dashboard, las estadísticas de ventas, la ficha de clientes y los reportes se dividieron en clases por responsabilidad. Sin cambios de comportamiento.
- Mensaje de rechazo único al escribir o agregar notas en una conversación ajena.

### Seguridad
- Un vendedor ya no puede cancelar promesas de pago de clientes de otro vendedor.
- Límite general de peticiones y límite de intentos de inicio de sesión por dirección; cabeceras de seguridad; sin `X-Powered-By` ni página de bienvenida.
- Las cuentas desactivadas dejan de ver `/auth/me` y de recibir tiempo real aunque conserven la sesión.
- Descarga de archivos de WhatsApp solo desde dominios de Meta; destino del webhook sin acceso a la red interna.
- Cookie de sesión `Secure` por omisión en producción; auditoría de dependencias que bloquea la CI.

### Corregido
- `company_id` de la URL se pasaba como texto a los servicios de crédito.
- La descarga CSV ante un fallo al abrir la salida seguía con un recurso inválido; ahora falla con un mensaje claro.
- El listado de facturas de un cliente ejecutaba consultas cuyo resultado no usaba.
- Un mes de metas con formato inválido devolvía `null` en silencio.

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
