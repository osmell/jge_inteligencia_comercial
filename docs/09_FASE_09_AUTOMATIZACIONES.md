# Fase 09 — Automatizaciones e integraciones

## Objetivo
Automatizar recordatorios y comunicaciones tras estabilizar los módulos centrales.

## Alcance
- Notificaciones internas y recordatorios.
- Alertas de cartera y cotizaciones próximas a vencer.
- Resúmenes periódicos para gerencia.
- Preparación de integración con correo y WhatsApp Business Platform.
- n8n para orquestación no crítica cuando sea conveniente.

## Reglas
- WhatsApp comienza como registro manual.
- Usar API oficial solo con credenciales y configuración aprobadas.
- Registrar envío/estado/resultado cuando el proveedor lo permita.
- Respetar opt-out y evitar envíos masivos no autorizados.
- Reglas financieras y permisos permanecen en backend.
- Reintentos sin duplicar mensajes u operaciones.

## Aceptación
Automatizaciones configurables, auditables, pausables y con registro de fallos.

## Preguntas abiertas
Ver `17_PREGUNTAS_FASE_09_AUTOMATIZACIONES.md` (qué avisar y a quién, canales, resúmenes, correo, WhatsApp, consentimiento y reglas de pausa y fallos).

## Qué ya existe y se reutiliza
Agenda de seguimiento y acciones planeadas; cotizaciones con vencimiento; cartera con tramos y promesas de pago (estado derivado); inventario con mínimos, máximos y faltantes; recomendaciones con gravedad y cierre; recálculo nocturno (`insights:refresh`) y colas con Redis; permisos por rol; Auditoría; marca «no contactar» del cliente. No hay todavía notificaciones internas, correo configurado ni WhatsApp.

## Pasos propuestos
Los pasos 1 a 4 funcionan sin ninguna credencial externa; el envío de correo (5) necesita el servidor de salida; WhatsApp (6) solo se prepara.

1. **Motor de notificaciones internas.** Tabla de notificaciones con destinatario, tipo, enlace, estado (nueva, vista, descartada) y clave de deduplicación; la campana en la aplicación con contador y lista; preferencias por persona (qué tipos recibe y si los pausa); y el alcance del destinatario respetado en el backend. Un aviso se identifica por su clave para que reintentos y recálculos no lo dupliquen.
2. **Reglas configurables y registro de ejecución.** Catálogo de automatizaciones (cada una con su regla, parámetros, destinatarios y estado activa/pausada), un interruptor general, permiso propio para administrarlas, Auditoría de cada cambio y un registro de cada ejecución con resultado, cantidad de avisos y fallos (con reintentos que no duplican).
3. **Avisos operativos.** Las primeras automatizaciones sobre el motor: acciones de seguimiento del día y vencidas, cotizaciones por vencer y vencidas, promesas de pago del día e incumplidas, facturas que cambian de tramo de cartera, productos bajo el mínimo o agotados y recomendaciones nuevas de gravedad alta; cada una con su umbral editable.
4. **Resúmenes para gerencia.** Resumen diario y semanal por compañía con ventas, metas, cartera, cotizaciones, inventario y recomendaciones; visible en la aplicación (historial de resúmenes) y listo para enviarse por correo.
5. **Correo interno y su configuración en la aplicación.** Pantalla de administración para configurar el servidor de correo (servidor, puerto, cifrado, usuario, contraseña cifrada y remitente) con prueba de envío; envío de los avisos y resúmenes por correo, con plantillas, registro de envío, estado y fallos, preferencias de horario y reintentos sin duplicar.
6. **WhatsApp: configuración en la aplicación y preparación.** Pantalla de administración para configurar la cuenta de WhatsApp Business (credenciales cifradas, número y prueba de conexión); plantillas de mensaje, registro de consentimiento y de opt-out, y registro de cada mensaje (enviado, entregado, leído, fallido). El registro manual de WhatsApp actual se conserva. El envío real se activa solo con plantillas aprobadas, y nunca a un cliente marcado «no contactar».
7. **Integración opcional con n8n.** Aviso firmado (webhook) hacia la dirección que configuren cuando ocurran eventos no críticos, con reintentos, registro y pausa; sin lógica de dinero, permisos ni inventario.

## Respuestas recibidas (responsable)
- Se aceptan las propuestas del cuestionario; las cuatro primeras (qué avisar, por dónde, preferencias por persona y a quién llega cada aviso) tal como se propusieron: campana en la aplicación y resumen por correo, preferencias por tipo, y cada aviso respeta el alcance de la persona.
- **Ya tienen servidor de correo:** se construye una **interfaz dentro de la aplicación para configurarlo** (servidor, puerto, usuario, contraseña, cifrado y remitente), con prueba de envío, para poder enviar las notificaciones.
- **Ya tienen una cuenta de WhatsApp Business:** se construye una **interfaz dentro de la aplicación para configurarla** (credenciales y número, con prueba de conexión). Mientras no haya plantillas aprobadas, WhatsApp sigue siendo registro manual y no se envía nada.
- Las credenciales de correo y de WhatsApp se guardan **cifradas** y nunca se muestran de vuelta; solo las ve y cambia la administración, con Auditoría.

## Paso 1 — Motor de notificaciones internas (hecho)
- **Campana en la aplicación** (arriba, junto al usuario): contador de avisos nuevos que se actualiza solo cada minuto, los 8 más recientes, «Marcar todo como visto» y acceso a la página **Avisos** (`/notificaciones`) con todos los avisos (vigentes, nuevos o descartados), paginados. Cada aviso se puede abrir (lleva a su pantalla), marcar como visto o descartar. Los vistos y descartados se conservan 90 días (`notifications:prune`, semanal).
- **Preferencias por persona:** apagar los tipos que no quiere y **pausar todos** sus avisos. Solo aparecen los tipos que corresponden a lo que su rol puede ver. Botón «Enviarme un aviso de prueba».
- **Tipos definidos** (`backend/config/notifications.php`, con el permiso que exige cada uno): acciones de seguimiento del día o vencidas, cotizaciones por vencer o vencidas, promesas de pago del día o incumplidas, facturas que pasan de tramo, productos bajo el mínimo o agotados, recomendaciones de gravedad alta y avisos del sistema. Los primeros seis los empiezan a generar los avisos operativos (paso 3).
- **Reglas del servidor:** un aviso solo se entrega a un usuario activo que tenga el permiso del tipo (nunca se avisa de algo que la persona no podría ver) y respetando sus preferencias. Cada aviso lleva una **clave de deduplicación**: repetir el envío (reintento o recálculo) no crea otro aviso, y uno descartado no reaparece por el mismo hecho. Cada persona solo ve y cambia sus propios avisos.
