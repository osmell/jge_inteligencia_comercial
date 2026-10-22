# ADR-005 — Autorización en capas: permiso de ruta, alcance de datos y Policy

- **Estado**: Aceptado · **Fecha**: 2026-10-21 · **Relacionado**: `docs/25_PROPUESTA_POLICIES.md`

## Contexto
Hay que responder tres preguntas distintas: ¿puede este rol usar esta función?, ¿qué registros ve esta persona?, ¿puede hacer esto con ESTE registro?

## Decisión
Tres capas, cada una con una sola responsabilidad:
1. **Permiso de ruta** (`permission:*` en `routes/api.php`): función habilitada para el rol; el Administrador concede permisos por rol.
2. **Alcance de datos** (`DataScope`, scopes globales, `ConversationAccess::visible`): qué filas entran en una consulta (compañías, sucursales, vendedores propios o supervisados).
3. **Policy** (`app/Policies`): qué se puede hacer con un registro ya visible. Los controladores llaman `authorize()`; el frontend recibe `can_*` de la misma fuente.

El servidor decide siempre; el menú del frontend solo oculta controles. `ArchitectureTest` impide comparar `role->slug` en controladores.

## Consecuencias
- «¿Qué puede hacer un supervisor?» se lee en un lugar y se prueba en `PoliciesTest`.
- Un registro ajeno responde 404 (no existe para esa persona) o 403 (existe pero no puede), según la Policy.
- Se mantienen roles fijos en el código para escribir en conversaciones (administración y gerencia solo leen).

## Alternativas descartadas
Solo middleware (no distingue registros) y un paquete externo de roles/permisos (el modelo propio ya cubría lo necesario).
