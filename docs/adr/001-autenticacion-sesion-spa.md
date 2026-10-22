# ADR-001 — Autenticación por usuario y sesión de cookie (Sanctum SPA)

- **Estado**: Aceptado · **Fecha**: 2026-10-18 · **Relacionado**: `docs/01_FASE_01_FUNDACION_SEGURIDAD.md`

## Contexto
La aplicación es una SPA (React) que consume una API Laravel del mismo sitio. El acceso es por **nombre de usuario** único, no por correo (decisión de la Fase 1).

## Decisión
Autenticación por **usuario y contraseña** con la sesión por cookie de **Laravel Sanctum en modo SPA** (CSRF incluido). El frontend y la API deben compartir sitio. No se emiten tokens personales para la interfaz.

## Consecuencias
- No hay tokens que guardar en el navegador ni que filtrar; el cierre de sesión es inmediato.
- En producción hay que configurar `FRONTEND_URL`, `SANCTUM_STATEFUL_DOMAINS`, `SESSION_DOMAIN` y `SESSION_SECURE_COOKIE=true` (ver README).
- Una app móvil o un cliente de otro dominio necesitaría tokens: sería otra decisión.
- Los canales de tiempo real (ADR-008) reutilizan esta sesión.

## Alternativas descartadas
Tokens JWT/Bearer en `localStorage` (superficie XSS mayor) e inicio de sesión por correo (la Fase 1 definió el acceso por nombre de usuario).
