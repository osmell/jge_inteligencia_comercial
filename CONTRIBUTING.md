# Guía de contribución

Proyecto: **JGE Inteligencia Comercial** (Laravel 13 / PHP 8.5 + React 19 / TypeScript + PostgreSQL).
Antes de empezar lee `docs/00_CONTEXTO_MAESTRO.md` y el documento de la fase en la que vas a trabajar. Los principios de diseño y el plan de mejora están en [`docs/24_ANALISIS_CODIGO_Y_GUIA_DESARROLLO.md`](docs/24_ANALISIS_CODIGO_Y_GUIA_DESARROLLO.md).

## Puesta en marcha
Sigue la sección «Arranque en desarrollo» del [README](README.md). Usuarios ficticios: ver README (solo entornos no productivos).

## Flujo de trabajo
1. Crea una rama desde `main`: `feature/<fase>-<tema>`, `fix/<tema>` o `docs/<tema>`.
2. Haz commits pequeños, en español, en imperativo y con alcance: `Conversaciones: enviar plantilla aprobada`. Un cambio lógico por commit.
3. Abre un Pull Request con la plantilla; necesita CI en verde y al menos una revisión.
4. `main` está protegida: no se sube directo.

## Chequeos obligatorios (los mismos que ejecuta la CI)
```bash
# Backend: estilo, análisis estático y pruebas
docker compose exec -T -u $(id -u) backend composer check
# Para aplicar el estilo automáticamente
docker compose exec -T -u $(id -u) backend vendor/bin/pint app config routes database tests

# Frontend
docker compose exec -T frontend sh -c "npm run typecheck && npm run lint && npm test && npm run build"
```

## Pruebas de extremo a extremo
Los flujos críticos (sesión, barra de comandos, menú por rol, ventas, administración, clientes, cotizaciones) se prueban en un navegador real con Playwright (`e2e/`, ver su README). Corren cada noche y a demanda en GitHub (*Actions → E2E*); un cambio que toque una de esas pantallas debe ejecutarlas antes de fusionarse.

## Reglas de código (resumen de SOLID para este proyecto)
- **Controlador delgado**: autorizar, validar con `FormRequest`, delegar a un servicio, responder. Sin `DB::` ni consultas encadenadas.
- **Una regla de negocio, un lugar**: búscala antes en `app/Services`. Un servicio = un caso de uso.
- **Extender sin editar**: un tipo nuevo de importación, automatización o alerta se agrega como clase nueva (`ImportType`, `Handler`).
- **Dependencias por constructor**; evita `app()`, `resolve()` y `new` de colaboradores dentro de la lógica.
- **Código nuevo con `declare(strict_types=1);`** y tipos de retorno explícitos.
- **Migraciones**: nunca se editan después de fusionadas; se añade una nueva. SQL portable (pruebas en SQLite, producción en PostgreSQL).
- **Frontend**: sin `fetch` fuera de `src/lib`; lógica con estado complejo en *hooks* (`useFilters`, `useSubmit`, `useFormValues`, `useExport`, `useDebounced`: úselos antes de escribir otra fila de `useState`); sigue `docs/12_SISTEMA_DE_DISENO.md`.

## Pruebas
- Toda funcionalidad nueva lleva pruebas; toda corrección de error empieza por una prueba que falla.
- La lógica pura (cálculos, ventanas, validaciones) va en `tests/Unit`; el contrato HTTP, permisos y alcance de datos en `tests/Feature`.
- Las pruebas del backend no tocan la base de desarrollo (SQLite en memoria). Con `Http::fake` el primer *stub* gana: usa `Http::sequence`.

## Seguridad
- Nunca subas secretos, `.env` ni datos reales de clientes.
- Credenciales (SMTP, WhatsApp, webhook) se guardan cifradas, no se devuelven al cliente ni se escriben en Auditoría.
- No envíes mensajes a clientes «no contactar»; el envío real de WhatsApp solo con plantillas aprobadas fuera de la ventana de 24 h.
- Cambios que afecten permisos, borrado o movimiento de datos se confirman con el responsable antes de implementarse.

## Documentación
- Cada fase tiene su documento en `docs/`; actualízalo en el mismo PR.
- Las modificaciones de la revisión se registran en `docs/23_AJUSTES_REVISION.md` y solo pasan a «Aprobado» con la aprobación del responsable.
- Decisiones técnicas importantes: ADR corto en `docs/adr/` (plantilla en `docs/adr/000-plantilla.md`).
- Todo cambio visible para quien usa el sistema añade una línea en **Sin publicar** de `CHANGELOG.md`.
- Operación y monitoreo (registros, `ops:status`, respaldos): `docs/26_OPERACION.md`.
