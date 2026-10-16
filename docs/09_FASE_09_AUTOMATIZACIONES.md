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
