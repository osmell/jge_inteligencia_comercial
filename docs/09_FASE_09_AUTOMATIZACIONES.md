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
5. **Correo interno.** Envío de los avisos y resúmenes por correo con el servidor que definan (mientras tanto, solo al registro), con plantillas, registro de envío, estado y fallos, preferencias de horario y reintentos sin duplicar.
6. **Preparación de WhatsApp y de correo a clientes.** Plantillas de mensaje, registro de consentimiento y de opt-out, y registro de cada mensaje (enviado, entregado, leído, fallido) sin enviar nada todavía; el registro manual de WhatsApp actual se conserva. El envío real se activa solo con credenciales y plantillas aprobadas, y nunca a un cliente marcado «no contactar».
7. **Integración opcional con n8n.** Aviso firmado (webhook) hacia la dirección que configuren cuando ocurran eventos no críticos, con reintentos, registro y pausa; sin lógica de dinero, permisos ni inventario.
