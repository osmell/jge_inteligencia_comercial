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

### Pendiente
2. Agenda del vendedor y del supervisor (próximas acciones, vencidas, clientes inactivos). 3. Cotizaciones. 4. KPI.
