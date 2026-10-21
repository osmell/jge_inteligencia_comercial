# Fase 11 — Conversaciones con clientes (ventana tipo WhatsApp Web)

## Objetivo (según lo pedido)
Una ventana de conversación parecida a WhatsApp Web donde cada vendedor ve **sus clientes** y conversa con ellos **en tiempo real**. Cada mensaje (del cliente y del vendedor) se guarda en la base de datos y los archivos adjuntos en una carpeta privada del servidor. El **supervisor** y el **administrador** pueden ver las conversaciones; **solo el supervisor** puede escribir al cliente, y únicamente si el cliente está asignado a su equipo. Vendedores y supervisores envían mensajes y archivos adjuntos como en WhatsApp.

## Lo que ya existe y se reutiliza
Cuenta de WhatsApp Business configurable (credenciales cifradas, prueba de conexión), plantillas, consentimiento por cliente, marca «no contactar», registro de mensajes enviados, webhook firmado de Meta (hoy solo para estados), campana de avisos, colas con Redis, alcance por rol (vendedor, supervisor, compañía), Auditoría y almacenamiento privado de archivos (cotizaciones).

## Realidades de WhatsApp que condicionan el diseño (importante)
1. **Vía oficial = WhatsApp Business Platform (Cloud API)**, la que ya está configurada. Todos los vendedores escriben desde **el número de la empresa**, no desde su teléfono personal. Cada conversación pertenece a (compañía, cliente); el vendedor responsable es el asignado al cliente.
2. **Ventana de 24 horas:** se puede escribir libremente solo durante las 24 h siguientes al último mensaje **del cliente**. Fuera de esa ventana Meta solo permite **plantillas aprobadas**; la pantalla debe mostrarlo («ventana cerrada: usa una plantilla»).
3. **Mensajes entrantes** llegan por el webhook (hay que ampliarlo; hoy solo procesa estados). Los archivos entrantes se descargan de Meta (enlaces que caducan en minutos) y se guardan en el servidor.
4. **Límites de archivos de Meta:** imágenes hasta 5 MB, audio y video hasta 16 MB, documentos hasta 100 MB; tipos permitidos acotados (jpg/png, mp4, ogg/opus, pdf, docx, xlsx…).
5. **Lo que la API no permite o no iguala:** editar o borrar para todos lo ya enviado, indicador de «escribiendo…» del cliente, estados/llamadas, grupos. Sí permite: texto, imágenes, video, audio/notas de voz, documentos, ubicación, **responder citando**, reacciones, y recibos de entregado/leído.
6. **Tiempo real:** requiere un canal persistente (WebSockets con Laravel Reverb) o, en su defecto, consulta frecuente. Implica un servicio más en Docker.
7. **Alternativa no oficial** (librerías que emulan WhatsApp Web con el teléfono de cada vendedor): **no se recomienda** —viola los términos de WhatsApp, puede bloquear el número, es frágil y no hay soporte—. Se menciona solo para que la decisión sea informada.

## Reglas que se mantienen o se proponen
- **Quién escribe:** vendedor, solo a sus clientes asignados; supervisor, solo a clientes de su equipo (además de lo que él ya registró); **administrador y gerencia, solo lectura** (si se confirma). Un cliente «no contactar» no se puede iniciar ni responder; la retirada de consentimiento cierra el envío.
- **Quién ve:** el vendedor, solo lo suyo; el supervisor, su equipo; administración (y gerencia, a confirmar), todo su ámbito. Mismos permisos y alcance que el resto del sistema.
- **Privacidad y control:** cada vez que supervisión o administración **abre una conversación ajena** queda en Auditoría (quién, cuándo, cuál); los adjuntos se guardan en almacenamiento **privado** (no en carpeta pública) y se sirven solo a quien tiene acceso a esa conversación; tipos y tamaños validados.
- **Los mensajes no se editan ni se borran** (se conservan como evidencia); se pueden **archivar**. Política de retención a definir.
- Un mensaje se guarda **una sola vez** (clave del mensaje de Meta) aunque el webhook se repita; fallos de envío quedan visibles con motivo y reintento manual, sin duplicar.
- Los textos y archivos del cliente son **datos no confiables**: se muestran escapados, nunca se ejecutan.

## Propuestas de mejora (para que decidas)
1. **Bandeja «sin asignar»:** si escribe un número que no está en clientes, la conversación queda en una bandeja que supervisión asigna o convierte en cliente nuevo; hoy se perdería.
2. **Notas internas** dentro de la conversación (visibles solo para el equipo, nunca enviadas al cliente) para que supervisor y vendedor se coordinen.
3. **Respuestas rápidas y plantillas** a un clic (saludo, horario, cotización lista), con variables del cliente.
4. **Enviar desde el sistema** una cotización (PDF adjunto) o el estado de cuenta del cliente, y que la conversación **enlace** a la ficha del cliente, sus cotizaciones y su cartera en un panel lateral.
5. **Registro automático en Seguimiento:** que un intercambio cuente como contacto del cliente (sin duplicar a mano) y actualice «último contacto»; **no** atribuye ventas.
6. **Indicadores de atención:** tiempo de primera respuesta, conversaciones sin responder, y avisos a supervisión cuando un cliente espera más de X minutos (se suman al dashboard).
7. **Transferir** una conversación a otro vendedor al reasignar el cliente, conservando el historial.
8. **Mensaje de ausencia / horario de atención** automático fuera de horario (solo dentro de la ventana de 24 h o con plantilla).
9. **Búsqueda** dentro de conversaciones y de todos los clientes, y etiquetas (p. ej. «cotizando», «reclamo»).
10. **Notificación** en la campana y sonido discreto con mensajes nuevos, y contador de no leídos por conversación.
11. **Copia de seguridad** de la carpeta de adjuntos junto con la base de datos.

## Pasos propuestos (a confirmar con el cuestionario `21_PREGUNTAS_FASE_11_CONVERSACIONES.md`)
1. Cuestionario y decisiones (vía oficial, tiempo real, quién escribe/ve, retención, adjuntos).
2. Modelo de datos y almacenamiento: conversaciones, mensajes (entrantes/salientes, estados, citas), adjuntos en disco privado, notas internas, permisos nuevos y alcance por rol; registro de acceso en Auditoría.
3. Recepción: ampliar el webhook firmado para mensajes entrantes (texto y adjuntos descargados a la carpeta privada), sin duplicados y con la bandeja «sin asignar».
4. Envío: texto, respuesta citada y adjuntos (imagen, video, audio, documento) con validaciones, regla de las 24 h con plantillas, reglas de consentimiento/«no contactar», reintento sin duplicar.
5. Tiempo real: canal de WebSockets (Laravel Reverb en Docker) con autorización por conversación, contadores de no leídos y avisos.
6. Ventana de conversación (lista de chats, burbujas, estados de entrega, adjuntos con vista previa, notas de voz, citas, búsqueda, panel del cliente), y vista de solo lectura para administración.
7. Supervisor: lectura e intervención en su equipo, notas internas, transferencias, indicadores de atención y registro en Seguimiento; pulido, rendimiento, copia de seguridad y documentación.

## Criterios de aceptación
- Un mensaje entrante llega a la ventana del vendedor correcto sin recargar y queda guardado una sola vez; los adjuntos quedan en carpeta privada y solo los abre quien tiene acceso.
- Vendedor y supervisor envían texto y archivos; administración no puede escribir; ningún rol ve conversaciones fuera de su alcance (probado por rol).
- Fuera de la ventana de 24 h solo se envían plantillas aprobadas; nunca se escribe a «no contactar».
- Toda lectura ajena por supervisión o administración queda en Auditoría.
- Pruebas automáticas de alcance, dedupe, validación de archivos y tiempo real; revisión con la cuenta real de WhatsApp Business.
