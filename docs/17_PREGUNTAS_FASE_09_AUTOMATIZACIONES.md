# Preguntas para el responsable — Fase 9 (Automatizaciones e integraciones)

Contexto: la Fase 9 automatiza recordatorios y avisos sobre lo que ya existe (agenda de seguimiento, cotizaciones, cartera, promesas de pago, inventario y recomendaciones). Todo debe poder configurarse, pausarse y auditarse; las reglas financieras y los permisos se quedan en el sistema, y WhatsApp comienza como registro manual. Cada pregunta trae una propuesta; basta con responder «de acuerdo» o corregirla.

## 1. Notificaciones internas
1. **¿Qué quieren que les avise el sistema?** *(Propuesta inicial: acción de seguimiento vencida o del día; cotización por vencer (3 días antes) o vencida; promesa de pago del día o incumplida; factura que pasa de tramo de cartera; producto bajo el mínimo o agotado; recomendación nueva de gravedad alta.)* ¿Falta alguno o sobra alguno?
2. **¿Por dónde reciben los avisos?** *(Propuesta: campana dentro de la aplicación y un resumen por correo; sin envío a WhatsApp ni SMS en esta etapa.)*
3. **¿Cada persona puede elegir qué avisos recibe y a qué hora?** *(Propuesta: sí; por tipo de aviso, con horario de envío del correo y la opción de pausar.)*
4. **¿A quién le llega cada aviso?** *(Propuesta: al vendedor responsable del cliente; al supervisor, lo de su equipo; a gerencia y administración, solo los resúmenes y lo grave. Un aviso respeta el mismo alcance que ya tiene la pantalla.)*

## 2. Alertas de cartera y cotizaciones
5. **Cotizaciones por vencer:** ¿con cuántos días de anticipación avisar? *(Propuesta: 3 días antes y el día del vencimiento.)*
6. **Cartera:** ¿qué debe avisar? *(Propuesta: una factura que pasa de tramo —por ejemplo, de 1-30 a 31-60 días—, una promesa de pago que vence hoy o se incumplió, y el saldo total de un cliente que pasa de los 90 días.)* ¿Hay un monto mínimo para no avisar por saldos pequeños?
7. **¿Los avisos de cobranza le llegan al vendedor, al cobrador o a ambos?** *(Propuesta: a quien registró la promesa de pago, y al vendedor de la factura; el cobrador solo si tiene el permiso de crédito.)*

## 3. Resúmenes para gerencia
8. **¿Qué resúmenes y con qué frecuencia?** *(Propuesta: un resumen diario corto —ventas de ayer contra el promedio, cartera vencida, cotizaciones por vencer— y uno semanal con ventas, metas por vendedor, clientes en riesgo y productos bajo el mínimo.)*
9. **¿Quién los recibe, por compañía?** *(Propuesta: gerencia general y administración; cada compañía por separado o consolidado, a elegir.)*
10. **¿A qué hora?** *(Propuesta: 7:00 a. m. los días hábiles.)*

## 4. Correo y WhatsApp
11. **Correo:** ¿cuál es el servidor de salida (SMTP) o el proveedor que usarán y con qué dirección remitente? Mientras no lo tengamos, los correos se guardan en un registro sin enviarse.
12. **¿Se enviarán correos a clientes** (por ejemplo, un recordatorio de cobro o una cotización) o solo internos? *(Propuesta: solo internos en esta etapa; el envío a clientes queda como un paso aparte con plantillas aprobadas, registro de cada envío y respeto del «no contactar».)*
13. **WhatsApp:** hoy se registra a mano. ¿Tienen una cuenta de WhatsApp Business Platform aprobada por Meta y plantillas de mensaje aprobadas? *(Propuesta: dejar preparada la estructura —plantillas, registro de envío, estado y resultado, opt-out— sin enviar nada hasta que existan las credenciales y la aprobación.)*
14. **Consentimiento:** ¿cómo se registra hoy que un cliente aceptó recibir mensajes? *(Propuesta: un registro de consentimiento por cliente y canal, además de la marca «no contactar» que ya existe.)*

## 5. Reglas de la automatización
15. **¿Quién puede crear, pausar y cambiar las automatizaciones?** *(Propuesta: un permiso aparte para administración; todo cambio con Auditoría; un interruptor general para pausar todo.)*
16. **Fallos:** *(Propuesta: reintentar hasta 3 veces con espera creciente sin duplicar el aviso, y dejar el fallo visible en una pantalla de administración con el motivo.)*
17. **n8n:** ¿quieren usarlo para algo no crítico (por ejemplo, enviar el resumen a un canal de chat o a una hoja de cálculo)? *(Propuesta: sí, opcional, mediante un aviso firmado hacia una dirección que ustedes configuren; nunca para lógica de dinero, permisos ni inventario.)*
18. **Horario:** ¿hay días y horas en que no deben enviarse avisos (fines de semana, feriados)? *(Propuesta: solo de lunes a sábado entre 7:00 a. m. y 7:00 p. m.)*
