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

## Paso 2 — Reglas configurables y registro de ejecución (hecho)
Pantalla **Automatizaciones** (`/admin/automatizaciones`, permiso nuevo `automations.manage`; no se otorga solo, se asigna en «Roles y permisos»). El programador de Laravel ejecuta cada 5 minutos `php artisan automations:run`, que decide qué toca; reemplaza las tareas sueltas que había (recálculo nocturno y limpieza de avisos), que ahora son automatizaciones del catálogo.
- **Catálogo** (`backend/config/automations.php`): cada automatización con su descripción, frecuencia (diaria, semanal o por hora), hora de ejecución y parámetros editables con rango. Ya funcionan **«Recálculo de la inteligencia comercial»** (2:00 a. m.) y **«Limpieza de avisos antiguos»** (lunes 3:00 a. m.). Las demás —acciones de seguimiento, cotizaciones por vencer (3 días de anticipación), promesas de pago, tramos de cartera (con saldo mínimo), inventario bajo el mínimo y recomendaciones graves— aparecen **«en preparación»** hasta el paso 3 y no corren.
- **Activar, pausar y cambiar parámetros** de cada una (hora, anticipación, saldo mínimo…), con Auditoría del valor anterior y el nuevo; **interruptor general** que pausa todo (ni solo ni a mano) y **ventana de avisos** configurable (por omisión de lunes a sábado, de 7:00 a 19:00): las automatizaciones que envían avisos a personas no corren fuera de ella; el mantenimiento sí.
- **Ejecutar ahora** (manual): queda registrada con quién la lanzó y no se reintenta sola.
- **Registro de ejecuciones:** inicio, origen (programada o manual), resultado (correcta o con error), intentos, resumen de lo hecho o el motivo del error, y cuándo se reintentará. Filtros por automatización y resultado.
- **Sin duplicados:** una ejecución programada se identifica por su periodo (día, semana u hora): aunque el programador corra muchas veces, no se repite. Cada automatización debe ser idempotente (los avisos ya usan clave de deduplicación).
- **Fallos y reintentos:** si una ejecución falla se reintenta 2 veces más (a los 5 y a los 15 minutos) **reutilizando la misma fila del registro**; si sigue fallando queda con su error visible. Una ejecución «en curso» que lleva más de 30 minutos se da por interrumpida y se reintenta.
- Las reglas financieras y los permisos siguen en el backend: una automatización solo avisa a quien podría ver lo que se le avisa.

## Paso 3 — Avisos operativos (hecho)
Las seis automatizaciones que estaban «en preparación» ya generan avisos reales en la campana. Corren a las **7:00 a. m.** (hora editable) de lunes a sábado, dentro de la ventana de avisos, y se pueden pausar o ajustar en **Automatizaciones**. **Cada aviso llega solo a quien lo puede ver** (se calcula con el alcance de esa persona: un vendedor, sus clientes y sus facturas; el resto, su ámbito de compañías y sucursales), respeta sus preferencias y su pausa, y lleva una clave de deduplicación: reintentar una ejecución no repite nada.
- **Acciones de seguimiento:** un resumen diario a cada persona con seguimiento («Tienes 2 acciones vencidas y 1 para hoy en tu agenda»), con las acciones planeadas desde recomendaciones incluidas. Sin acciones pendientes no se avisa.
- **Cotizaciones por vencer y vencidas:** a quien la registró y al usuario del vendedor, con la anticipación configurable (3 días por omisión) y el mismo día; las vencidas en los últimos 3 días se avisan una vez. Solo cotizaciones vigentes (emitidas, enviadas o en seguimiento); una aceptada no vence.
- **Promesas de pago:** «vence hoy» mientras no se haya completado el abono, e «incumplida» al pasar la fecha sin completarse (el estado se deriva de los abonos). Se avisa a quien la registró y a los vendedores de las facturas del cliente.
- **Facturas que pasan de tramo de cartera:** un resumen a cada persona con acceso a crédito de las facturas (en su alcance) que cambiaron de tramo en los últimos dos días, con el saldo total y las primeras facturas; hay un saldo mínimo configurable. Un resumen idéntico no se repite.
- **Inventario bajo el mínimo o agotado:** un resumen diario a cada persona con acceso al inventario, con los productos más urgentes de las sucursales que ve (usa la misma rotación y los mismos mínimos de la pantalla de Rotación).
- **Recomendaciones graves:** un resumen a cada persona con acceso a inteligencia comercial de las recomendaciones de gravedad alta detectadas en los últimos dos días en los clientes que ve.
- **Pendiente del cuestionario:** si los avisos de cobranza deben llegar también a un cobrador distinto del vendedor (hoy llegan a quien registró la promesa, al vendedor y a quien tiene acceso a crédito en su alcance).

## Paso 4 — Resúmenes para gerencia (hecho)
Pantalla **Resúmenes** (`/resumenes`, permiso nuevo `digests.view`; no se otorga solo): historial de resúmenes con filtro por compañía y tipo, y el detalle de cada uno (`/resumenes/:id`) con sus secciones, cifras (en verde o rojo según corresponda) y tablas. Dos automatizaciones nuevas, con las demás en **Automatizaciones** (activables, pausables, con hora editable, de lunes a sábado dentro de la ventana de avisos): **Resumen diario para gerencia** (7:00) y **Resumen semanal para gerencia** (lunes, 7:00). Cada una genera el resumen de cada compañía activa y envía un aviso a la campana de quienes lo pueden ver.
- **Resumen diario** (del día anterior): venta neta de ayer **contra el promedio de ese mismo día de la semana en las 4 semanas previas** (solo cuentan los días con ventas; con menos de 2 comparables no se compara) y venta del mes a la fecha; cartera (saldo por cobrar, vencido, con más de 90 días, promesas de pago que vencen hoy o incumplidas en 7 días); cotizaciones (abiertas, por vencer en 3 días, vencidas sin cerrar); seguimiento (acciones vencidas y de hoy de toda la compañía); inventario (agotados, bajo el mínimo y sin rotación).
- **Resumen semanal** (semana anterior, lunes a domingo): venta neta contra la semana previa, principales 5 clientes y productos; **avance de metas por vendedor** del mes (solo vendedores con meta; aparece cuando hay metas capturadas); cartera; cotizaciones (abiertas, por vencer, emitidas y convertidas en factura en la semana); recomendaciones comerciales (graves abiertas, clientes en riesgo RFM, con caída de venta y en riesgo de inactividad); inventario.
- **Cifras de toda la compañía:** solo los ven quienes tienen acceso completo a ella (un usuario limitado a una sucursal no recibe ni ve el resumen de la compañía).
- **Se generan una sola vez por periodo y guardan las cifras tal como estaban**: un dato corregido después no cambia un resumen ya generado (el historial sirve para comparar). Quien administra las automatizaciones puede generar a mano el del periodo actual («Generar el diario / el semanal»).
- Quedan listos para enviarse por correo cuando se configure el servidor (paso 5); hoy llegan a la campana con un enlace al resumen.
- **Supuestos pendientes de confirmar** (cuestionario, preguntas 8 a 10): contenido de cada resumen, que gerencia general y administración los reciban, y la hora (7:00). El resumen consolidado de varias compañías no está hecho: cada compañía va por separado.

## Paso 5 — Correo interno (hecho)
- **Pantalla «Correo»** (`/admin/correo`, permiso nuevo `mail.manage`, solo administración): servidor SMTP, puerto, cifrado (STARTTLS, SSL o ninguno), usuario, contraseña, remitente y nombre, activar/desactivar, **correo de prueba** y registro de envíos. La contraseña se guarda **cifrada**, nunca se devuelve ni aparece en Auditoría (solo «cambiada»); vacía al guardar = se conserva la actual.
- **Avisos por correo:** cada persona decide por tipo de aviso en «Avisos» si lo quiere también por correo (por omisión solo los resúmenes). Se usa el correo del usuario; sin correo o con el servidor sin activar, el envío queda en el registro como **No enviado** (nunca se pierde en silencio ni sale por otra vía).
- **Sin duplicados:** cada correo tiene clave (aviso + destinatario); una repetición no lo manda dos veces. Los resúmenes salen con sus cifras en el cuerpo y un enlace.
- **Envío y reintentos:** automatización «Envío de correos» (cada hora, ventana de avisos); si falla se reintenta a los 10 y 60 minutos y luego queda como **Falló** con el motivo. Un envío sin confirmar tras 30 min no se reenvía solo.
- Pendiente de confirmar: dirección remitente definitiva y los datos reales del servidor (se capturan en la pantalla).
