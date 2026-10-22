# ADR-009 — Puertos (interfaces) para WhatsApp y webhooks

- **Estado**: Aceptado · **Fecha**: 2026-10-21 · **Relacionado**: `docs/24_ANALISIS_CODIGO_Y_GUIA_DESARROLLO.md` (paso H)

## Contexto
Los servicios llamaban directamente a `Http::` y a la dirección de Meta; cambiar de proveedor o probar sin red exigía tocar la lógica de negocio.

## Decisión
Interfaces pequeñas por rol en `app/Contracts` (`WhatsappMessaging`, `WhatsappMediaSource`, `WebhookTransport`) con su implementación en `app/Integrations` (`GraphApiWhatsapp`, `HttpWebhookTransport`), enlazadas en `AppServiceProvider`. Ninguna clase de servicio usa `Http::` (lo comprueba `ArchitectureTest`). Los puertos devuelven el `Response` de Laravel para que las pruebas con `Http::fake` sigan valiendo.

## Consecuencias
- Cambiar de proveedor es una implementación nueva y un enlace; las pruebas pueden usar un doble sin red (`FakeWhatsappMessaging`).
- El tipo `Response` de Laravel se filtra en la interfaz; si se cambia de proveedor con respuestas muy distintas habrá que introducir un objeto propio.

## Alternativas descartadas
Dejar las llamadas en los servicios (acoplamiento) y envolver todo en un cliente genérico de «mensajería» (abstracción prematura con un solo proveedor).
