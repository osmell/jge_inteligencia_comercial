# Preguntas para el responsable — Fase 11 (Conversaciones con clientes)

Contexto: ver `20_FASE_11_CONVERSACIONES.md`. Cada pregunta trae una propuesta; basta con responder «de acuerdo» o corregirla.

## 1. Vía de WhatsApp (la decisión más importante)
1. **¿Se usará la WhatsApp Business Platform oficial (Cloud API), con el número de la empresa?** *(Propuesta: sí. Los vendedores escriben desde el número de la empresa; no se usa el teléfono personal de cada uno.)* ¿Tienen un solo número para todo el grupo o uno por compañía?
2. **¿Entienden y aceptan la ventana de 24 horas?** Fuera de ella solo se pueden enviar plantillas aprobadas por Meta. *(Propuesta: la pantalla avisa cuando la ventana está cerrada y ofrece las plantillas aprobadas.)*
3. **¿Alguien propone usar los teléfonos personales de los vendedores** (WhatsApp Web no oficial)? *(Propuesta: no; viola los términos de WhatsApp, puede bloquear el número y es frágil.)*

## 2. Quién ve y quién escribe
4. **Vendedor:** ve y escribe solo a los clientes que tiene asignados. ¿De acuerdo?
5. **Supervisor:** ve y escribe a los clientes de los vendedores que supervisa. Entiendo que «siempre y cuando lo tenga asignado» significa **cliente de su equipo**. ¿Es así, o se refiere a una asignación directa del cliente al supervisor? *(Propuesta: cliente de su equipo.)*
6. **Administrador:** solo lectura. **¿Gerencia general** también ve las conversaciones (solo lectura) o no? *(Propuesta: sí, solo lectura, con el mismo registro de acceso.)*
7. **Cuando supervisor o administrador abren una conversación ajena** queda registrado en Auditoría (quién, cuándo, cuál). ¿De acuerdo? *(Propuesta: sí; es una garantía de privacidad para vendedores y clientes.)*
8. **Cuando un vendedor está ausente**, ¿el supervisor puede responder desde la misma conversación? *(Propuesta: sí, y el cliente ve el mismo número de la empresa.)* ¿Se identifica en la conversación qué persona escribió cada mensaje? *(Propuesta: sí, solo para el equipo; el cliente no lo ve.)*

## 3. Mensajes y archivos
9. **Tipos de mensaje:** texto, imágenes, video, audio y notas de voz, documentos, ubicación, respuestas citando un mensaje y reacciones con emoji. ¿Falta alguno que necesiten? *(No se pueden editar ni borrar para todos los mensajes ya enviados: la API de Meta no lo permite, y además queremos conservarlos.)*
10. **Archivos:** límites de Meta: imágenes 5 MB, audio y video 16 MB, documentos 100 MB. *(Propuesta: aceptar solo esos tipos y tamaños, en una carpeta privada del servidor con copia de seguridad.)* ¿Cuánto espacio calculan por mes?
11. **Retención:** *(Propuesta: conservar mensajes y adjuntos mientras el cliente exista, con opción de archivar conversaciones; sin borrado automático hasta que ustedes definan una política.)* ¿Hay requisito legal o interno?
12. **Aviso al cliente:** ¿necesitan un texto que informe que las conversaciones se guardan? *(Propuesta: sí, incluido en la plantilla de bienvenida / consentimiento.)*

## 4. Tiempo real y avisos
13. **¿Cuántos vendedores usarían la ventana a la vez?** *(Propuesta: servicio de WebSockets (Laravel Reverb) en Docker; sirve de sobra para decenas de usuarios.)*
14. **Avisos:** contador de no leídos, aviso en la campana y sonido discreto con mensajes nuevos; aviso a supervisión si un cliente espera más de X minutos. ¿Cuántos minutos? *(Propuesta: 15 minutos en horario de atención.)*
15. **Horario de atención:** ¿cuál es? *(Propuesta: el mismo de la ventana de avisos, lunes a sábado.)* ¿Mensaje automático de ausencia fuera de horario? *(Propuesta: sí, dentro de la ventana de 24 h.)*

## 5. Clientes nuevos y asignación
16. **Número desconocido que escribe:** *(Propuesta: va a una bandeja «sin asignar»; supervisión la asigna a un vendedor o la convierte en cliente nuevo.)*
17. **Cliente reasignado a otro vendedor:** *(Propuesta: la conversación pasa al nuevo vendedor con todo su historial.)*
18. **Clientes «no contactar» o sin consentimiento:** *(Propuesta: si el cliente escribe primero, se puede responder dentro de la ventana de 24 h; si pidió no ser contactado, no se inicia ninguna conversación nueva.)* ¿De acuerdo?

## 6. Integración con el resto del sistema
19. **Panel lateral del cliente** en la conversación (ficha, cotizaciones, cartera, promesas de pago). *(Propuesta: sí, respetando los permisos de cada quien.)*
20. **Enviar desde la conversación** una cotización (PDF) o el estado de cuenta del cliente. *(Propuesta: sí, como adjunto, con registro.)*
21. **Seguimiento:** ¿un intercambio de mensajes cuenta como contacto del cliente en Seguimiento (último contacto)? *(Propuesta: sí, automático y sin duplicar lo que el vendedor ya registró a mano.)*
22. **Indicadores:** tiempo de primera respuesta, conversaciones sin responder y volumen por vendedor, en el dashboard del supervisor. *(Propuesta: sí.)*
23. **Notas internas, respuestas rápidas, etiquetas y búsqueda** (propuestas 2, 3 y 9 del documento). ¿Cuáles quieren en la primera versión?

## 7. Cuenta y pruebas
24. **¿Ya tienen la cuenta de WhatsApp Business aprobada en Meta y el número en modo producción?** Para probar de verdad hace falta una cuenta real (o el número de pruebas de Meta). *(Propuesta: empezar con el número de pruebas de Meta y pasar al real al validar.)*
