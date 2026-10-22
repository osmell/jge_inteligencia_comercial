# ADR-007 — WhatsApp Cloud API, tabla de mensajes unificada y ventana de 24 h

- **Estado**: Aceptado · **Fecha**: 2026-10-20 · **Relacionado**: `docs/20_FASE_11_CONVERSACIONES.md`, `docs/21_PREGUNTAS_FASE_11_CONVERSACIONES.md`

## Contexto
Se necesita una ventana de conversación tipo WhatsApp Web por vendedor, con envío y recepción reales, sin perder nada de lo conversado.

## Decisión
- Canal oficial: **WhatsApp Cloud API** de Meta (credenciales cifradas, configuradas dentro de la aplicación).
- Una sola tabla `whatsapp_messages` para plantillas, avisos, mensajes entrantes y salientes (campo `kind` y estado), con adjuntos en disco privado.
- **Ventana de 24 h**: texto y archivos libres solo dentro de ella; fuera, solo plantillas aprobadas. Nunca se escribe a clientes «no contactar».
- Las conversaciones **no se borran**. Administración y gerencia solo leen; abrir una conversación ajena queda en Auditoría.
- Envío en cola con reintentos (5xx/429/red) y falla definitiva con 4xx; recepción por webhook firmado y sin duplicados.

## Consecuencias
- El historial es completo y auditable; el volumen crece (los adjuntos se verifican con `conversations:verify-files`).
- Si Meta acepta un mensaje y se pierde la respuesta, un reintento podría duplicarlo (limitación conocida).
- Depende de aprobaciones de plantillas por Meta.

## Alternativas descartadas
WhatsApp Web no oficial (viola términos de uso y se bloquea) y proveedores intermediarios (costo y dependencia adicionales).
