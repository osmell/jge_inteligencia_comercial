# Fase 05 — Seguimiento comercial y cotizaciones

## Objetivo
Crear una agenda diaria priorizada y registrar interacciones, respuestas, próximas acciones y cotizaciones.

## Alcance
- Canales: llamada, correo, WhatsApp manual, visita, reunión y otros.
- Resultado, respuesta, fecha/hora, responsable, próxima acción y adjuntos.
- Agenda por vendedor y supervisión de actividades vencidas.
- Reglas de inactividad configurables.
- Cotizaciones con PDF/foto, importe, vencimiento, estado, motivo de pérdida y vínculo explícito con factura.
- KPI de contactos, cumplimiento, clientes nuevos/recuperados y conversión.

## Estados sugeridos
Borrador, Emitida, Enviada, En seguimiento, Aceptada, Convertida en venta, Rechazada, Vencida y Cancelada.

## Reglas
- Una cotización aceptada no es una venta hasta existir documento de venta válido.
- No atribuir automáticamente una venta posterior a un contacto.
- Tasa de conversión = cotizaciones convertidas / cotizaciones elegibles emitidas en una cohorte definida.
- Registrar también contactos sin respuesta.
- Respetar solicitudes de no recibir comunicaciones.
- WhatsApp se registra manualmente en esta fase.

## Aceptación
El vendedor registra actividad, resultado y siguiente acción; adjunta cotización; supervisor consulta oportunidades y tareas vencidas.

## Decisiones (2026-10-19)
- **Adjuntos de cotización:** se guardan en el servidor, con un límite de **15 MB** por archivo (PDF e imágenes).
- **Quién interactúa:** el **vendedor** y el **supervisor**. Se materializa con el permiso `interactions.manage` («Seguimiento de clientes»), que se otorga a esos roles desde **Roles y permisos** junto con `customers.view`. Un vendedor solo llega a sus clientes asignados; el supervisor, a los de su compañía. Corregir un contacto: solo su autor o quien tenga `customers.manage`. Los contactos no se borran.
- **Cotizaciones:** número propio automático más una referencia externa opcional.

## Avance
### Paso 1 — Interacciones con clientes (hecho)
- Tabla `customer_interactions`: canal (llamada, correo, WhatsApp manual, visita, reunión, otro), quién inició (cliente o vendedor), resultado, fecha y hora, respuesta/notas, próxima acción con fecha y responsable (quien lo registra).
- Resultados: se habló con el cliente, sin respuesta, interesado, pidió cotización, pidió que se le vuelva a contactar, no interesado, contacto incorrecto. **No existe «vendió»**: una venta solo existe con factura y no se atribuye a un contacto.
- **No contactar:** el cliente tiene la marca con motivo, fecha y quién la registró. Con la marca no se registran contactos salientes ni próximas acciones; sí los que inicia el cliente. Se audita al activar y retirar.
- API: `GET customers/interactions`, `POST customers/interactions`, `PUT customers/interactions/{id}`, `PUT customers/contact-preference`. Auditoría: `customer_interactions.created|updated`, `customers.contact_preference_updated`.
- Pantalla: sección «Seguimiento comercial» en la ficha del cliente.

### Paso 2 — Agenda de seguimiento (hecho)
- Pantalla **Agenda** (`/agenda`, permisos `customers.view` + `interactions.manage`) y API `GET customers/agenda`.
- **Acciones vencidas y próximas:** las próximas acciones de los contactos que siguen pendientes. Se marcan **cumplidas** (también desde la ficha del cliente) con `PUT customers/interactions/{id}/complete` y se pueden reabrir; cambiar la próxima acción de un contacto la deja otra vez pendiente. Auditoría: `customer_interactions.action_completed|action_reopened`.
- **Clientes por reactivar:** clientes inactivos o perdidos (según las reglas de actividad vigentes), que aceptan comunicaciones y sin contacto registrado en los últimos N días (15, 30, 60 o 90; por defecto 30). Inactivos primero; dentro de cada grupo, la compra más reciente. Se muestran hasta 50.
- **Alcance:** el vendedor, solo sus clientes; el supervisor, los clientes de los vendedores que tiene asignados como supervisor (catálogo de Vendedores) y lo que él mismo registró; el resto de roles, todo su ámbito de compañías. Si un supervisor no está asignado a ningún vendedor, su agenda sale vacía.
- Filtros: compañía, vendedor, plazo de las acciones (hoy, 7, 15, 30 días), días sin contacto y «solo lo que yo registré».
- No hay notificaciones ni correos: la agenda se consulta en pantalla.

### Paso 3 — Cotizaciones (hecho)
- Pantallas **Cotizaciones** (`/cotizaciones`) y detalle (`/cotizaciones/:id`); sección «Cotizaciones» en la ficha del cliente. Consulta con `customers.view`; alta y cambios con `interactions.manage` (el mismo permiso del seguimiento). Un vendedor solo llega a las de sus clientes.
- **Numeración:** `COT-000001`, consecutiva por compañía, más una **referencia externa** opcional.
- **Estados guardados:** Borrador, Emitida, Enviada, En seguimiento, Aceptada, Convertida en venta, Rechazada y Cancelada. **Vencida** no se guarda: se muestra cuando una cotización emitida, enviada o en seguimiento pasa de su fecha de vencimiento (una aceptada espera su factura y no vence); ampliar el vencimiento la deja vigente.
- **Transiciones:** Borrador → Emitida/Cancelada; Emitida → Enviada/En seguimiento/Aceptada/Rechazada/Cancelada; Enviada → En seguimiento/Aceptada/Rechazada/Cancelada; En seguimiento → Aceptada/Rechazada/Cancelada; Aceptada → Rechazada/Cancelada. Rechazada, Cancelada y Convertida son finales. Cada cambio queda en el historial de la cotización y en Auditoría.
- **Emitir** exige importe mayor que cero y fecha de vencimiento no pasada. **Rechazar** exige motivo (precio, competencia, sin presupuesto, no respondió, sin disponibilidad u otro). No se puede **enviar** a un cliente marcado «no contactar».
- **Venta:** una cotización aceptada **no es una venta**. Solo pasa a «Convertida en venta» al **vincular una factura** del mismo cliente y compañía, emitida desde la cotización y no vinculada a otra. Quitar el vínculo (por si fue un error) exige `customers.manage` y devuelve la cotización a «Aceptada». No se atribuye automáticamente ninguna venta a una cotización o contacto.
- **Adjunto:** un archivo por cotización (PDF, JPG, PNG o WebP) de hasta **15 MB**, guardado en el servidor en un disco privado (`storage/app/private/quotations`) y descargable solo con sesión y permiso. Se reemplaza o se quita mientras la cotización siga abierta.
- **Infraestructura:** PHP del contenedor admitía solo 2 MB por archivo; se agregó `backend/docker/uploads.ini` (20 MB por archivo, 24 MB por petición, cubre también las importaciones de 20 MB). **Hay que reconstruir la imagen:** `docker compose build backend queue` y volver a levantar.
- Auditoría: `quotations.created|updated|status_changed|linked|unlinked|attachment_added|attachment_removed`.

### Pendiente
4. KPI: contactos, cumplimiento, clientes nuevos y recuperados, y conversión por cohorte (usa el historial de estados de las cotizaciones).
