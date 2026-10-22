# ADR-008 — Tiempo real con Laravel Reverb y canales privados por persona

- **Estado**: Aceptado · **Fecha**: 2026-10-21 · **Relacionado**: `docs/20_FASE_11_CONVERSACIONES.md` (paso 5)

## Contexto
Las conversaciones y la campana de avisos deben actualizarse sin recargar, y cada persona solo debe recibir lo que puede ver.

## Decisión
**Laravel Reverb** (WebSockets propio, protocolo Pusher) como servicio `reverb` del Compose. Un **canal privado por persona** (`users.{id}.conversations` y `users.{id}.notifications`); el servidor decide a quién emitir cada evento según el alcance por rol (`ConversationAccess::watchers`). Los canales se autorizan con la misma sesión de la SPA (ADR-001). Si el tiempo real falla, la pantalla sigue funcionando por consulta periódica.

## Consecuencias
- Sin servicio externo ni costo por mensaje; un proceso más que operar y monitorear.
- Emitir a cada observador cuesta una consulta por evento; aceptable al volumen actual.
- En producción se requiere TLS para el WebSocket (`REVERB_SCHEME`).

## Alternativas descartadas
Pusher/Ably (costo y datos fuera de la infraestructura) y solo sondeo periódico (retraso y carga).
