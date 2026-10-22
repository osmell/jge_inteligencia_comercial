# Análisis del código, principios SOLID y guía de desarrollo en equipo

> Fecha del análisis: 2026-10-21. Es una foto medida sobre el repositorio (no una opinión): las cifras salen de contar archivos, líneas y patrones. Este documento **no cambia código**; propone un plan que se ejecuta por pasos y con aprobación, igual que el resto del proyecto.

## 1. Tamaño y forma del proyecto

| Zona | Archivos | Líneas |
|---|---|---|
| Backend `app/Services` | 54 | ~7 980 |
| Backend `app/Http` (controladores, requests, resources, middleware) | 81 | ~5 170 |
| Backend `app/Imports` (tipos de importación) | 26 | ~3 640 |
| Backend `app/Models` | 52 | ~2 570 |
| Backend `app/Automations` | 14 | ~440 |
| Backend tests (todos «Feature») | 65 | ~12 970 |
| Frontend `src` (páginas, componentes, lib) | — | pág. más grande 425 líneas |
| Frontend tests | 24 archivos | 242 pruebas |

Pruebas: 572 en backend, 242 en frontend. Documentación: 24 documentos en `docs/`.

## 2. Fortalezas

1. **Calidad automatizada local**: `composer check` (Pint + PHPStan/Larastan nivel 5 + pruebas), `tsc` estricto, oxlint y Vitest. Ninguno tiene excepciones masivas: no hay *baseline* de PHPStan y solo 6 `eslint-disable`/`any` en el frontend.
2. **Servicios con responsabilidad acotada**: 54 servicios con nombre de dominio (`CreditAging`, `QuotationWorkflow`, `InventoryLedger`…). Solo 4 pasan de 300 líneas. 38 usan constructor (inyección de dependencias).
3. **Validación en el borde**: 35 `FormRequest`; los controladores casi nunca validan a mano.
4. **Seguridad por capas coherente**: permisos por middleware, alcance de datos (`DataScope`, `OrganizationScope`), auditoría automática por trait de modelo, credenciales cifradas y nunca devueltas al cliente.
5. **Puntos de extensión ya correctos (OCP)**: `ImportType` (26 tipos de importación) y `Automations\Handler` son interfaces con muchas implementaciones; añadir un tipo no obliga a tocar el motor.
6. **Frontend ordenado**: acceso HTTP solo en `lib/api.ts`; un módulo `lib/*.ts` por dominio con tipos propios (~160 tipos exportados); componentes de chat divididos por responsabilidad.
7. **Trabajo pesado fuera de la petición**: colas (`SendWhatsappMessage`, importaciones), comandos programados, tiempo real con Reverb.
8. **Documentación viva**: contexto maestro, decisiones, una guía por fase y el registro de ajustes (`docs/23`). Es poco común y muy valioso para un equipo nuevo.
9. **Portabilidad de base de datos**: pruebas en SQLite, producción en PostgreSQL, SQL portable.

## 3. Debilidades (ordenadas por impacto en mantenimiento)

| # | Debilidad | Evidencia | Riesgo para un equipo |
|---|---|---|---|
| D1 | **Sin integración continua** | No existe `.github/workflows` | Cualquiera puede subir código que rompe `check`; no hay revisión obligatoria |
| D2 | **Sin `declare(strict_types=1)`** | 0 de 247 archivos de `app/` | Coerciones silenciosas de tipos (`"1"` vs `1`); errores que PHPStan nivel 5 no ve |
| D3 | **PHPStan en nivel 5** (de 10) | `phpstan.neon` | Tipos de retorno, genéricos y `mixed` quedan sin revisar |
| D4 | **Lógica de consulta en controladores** | 10 controladores usan `DB::` y 28 usan `->where(`; los peores: `ConversationToolsController`, `NotificationController`, `ConversationController`, `QuotationController` | Reglas duplicadas, difíciles de probar sin HTTP; violan SRP |
| D5 | **Sin pruebas unitarias** | `tests/Unit` vacío; 65 archivos «Feature» | Pruebas lentas, errores difíciles de localizar; la lógica pura (cálculos de RFM, aging, scoring) se prueba solo vía API |
| D6 | **Sin Policies** | 0 clases en `app/Policies` | La autorización de «¿puede ESTE usuario tocar ESTE registro?» está repartida entre middleware, `DataScope` y condiciones dentro del controlador |
| D7 | **Servicios grandes y mezclados** | `DashboardService` 381, `SalesStatistics` 356, `CustomerInsights` 339, `SalesReportExporter` 328 líneas; `ImportType` de ventas 433 | Varios motivos de cambio por clase (SRP) |
| D8 | **Servicios como clases con acoplamiento directo** | 39 usos de `app()`/`resolve()` (localizador de servicios) y 17 `new Clase(` en servicios/HTTP; solo 2 interfaces propias | Difícil sustituir por doble en pruebas; dependencias ocultas (DIP) |
| D9 | **Mucha lógica en componentes React** | Páginas con 10–18 `useState` (`InsightsAlertsPage`, `InventoryUnitsPage`, `QuotationsPage`, `UsersPage`) | Estados cruzados, componentes difíciles de probar y reutilizar |
| D10 | **Sin contrato de API formal** | Solo 3 `Resource`; tipos TS escritos a mano en `lib/*.ts` | El backend y el frontend pueden desalinearse sin que nada avise |
| D11 | **Convenciones solo en la costumbre** | No hay `CONTRIBUTING`, plantilla de PR, ni guía de ramas/commits escrita; `README` aún dice «Fase 1» | Un integrante nuevo tarda en saber «cómo se hace aquí» |
| D12 | **Pruebas de extremo a extremo y del navegador ausentes** | Solo Vitest con mocks; Meta/Reverb simulados | La integración real (docs/14) depende de QA manual |
| D13 | **Observabilidad básica** | Sin métricas ni trazas; auditoría sí | Diagnóstico en producción lento |

## 4. Parámetro SOLID para este proyecto

Cada principio se traduce en una **regla comprobable** (se verifica en la revisión de código del PR) y, cuando se puede, en una **medida automática**.

### S — Responsabilidad única
- **Regla**: una clase tiene un solo motivo de cambio. Si su descripción necesita «y», se divide.
- **Capas**: Controlador = recibir, autorizar, delegar, responder (≤ ~15 líneas por método). Servicio/Acción = una regla de negocio. Modelo = datos, relaciones, *scopes*. Request = validación. Resource = forma de la respuesta.
- **Medida**: controlador > 200 líneas o con `DB::`/consultas encadenadas → se mueve a servicio o *scope*; servicio > 300 líneas → se divide por caso de uso (p. ej. `DashboardService` en una sección por clase).

### O — Abierto/cerrado
- **Regla**: añadir un caso nuevo (tipo de importación, automatización, canal, regla de alerta) se hace **añadiendo una clase**, no editando un `if/switch` central.
- **Estado**: cumplido en `ImportType` y `Handler`. Se extiende el patrón a: tipos de alerta de insights, secciones del dashboard y canales de envío.
- **Medida**: un `match`/`switch` por tipo que crece en cada fase es señal de refactor.

### L — Sustitución de Liskov
- **Regla**: cualquier implementación de una interfaz debe poder usarse sin que el llamador conozca la clase concreta; no lanzar excepciones nuevas ni exigir precondiciones más fuertes.
- **Medida**: toda interfaz tiene una **prueba de contrato** compartida que se ejecuta contra cada implementación (ya hay base: pruebas por tipo de importación).

### I — Segregación de interfaces
- **Regla**: interfaces pequeñas y por rol (`Sends`, `Validates`, `Previews`), no una interfaz grande que obliga a métodos vacíos.
- **Medida**: si una implementación deja métodos vacíos o lanza «no soportado», la interfaz se divide.

### D — Inversión de dependencias
- **Regla**: las clases de dominio dependen de **abstracciones** en su constructor; no usan `app()`, `new` de colaboradores ni fachadas dentro de la lógica de negocio. Los puertos externos (WhatsApp, correo, webhook, almacenamiento) tienen interfaz y un doble para pruebas.
- **Medida**: `app()`/`resolve()` fuera de proveedores = 0 (hoy 39); `new` de servicios en HTTP/servicios = 0 (hoy 17).

### Lista de revisión para cada PR (se pega en la plantilla)
- [ ] ¿La clase nueva o cambiada tiene un solo motivo de cambio?
- [ ] ¿Se agregó un caso sin editar un `switch/if` central?
- [ ] ¿Las dependencias llegan por constructor y son abstracciones en los bordes?
- [ ] ¿La regla de negocio tiene prueba unitaria (sin HTTP ni base de datos si es posible)?
- [ ] ¿La autorización está en Policy/middleware y no a mano en el método?
- [ ] ¿Pasa `composer check`, `typecheck`, `lint`, `test` y `build`?
- [ ] ¿Se actualizó el documento de la fase y, si es un ajuste, `docs/23`?

## 5. Guía de buenas prácticas para el equipo

### 5.1 Arquitectura backend (destino)
```
Http/Controllers   → delgados: autorizar, validar (FormRequest), delegar, devolver Resource
Http/Requests      → validación y normalización
Http/Resources     → forma única de la respuesta (contrato con el frontend)
Policies           → autorización por registro
Services (Acciones)→ un caso de uso por clase, constructor con dependencias
Models             → relaciones, scopes, casts; sin reglas de negocio extensas
Contracts          → interfaces de puertos externos y puntos de extensión
Jobs/Events        → trabajo asíncrono y notificaciones de dominio
```
- `declare(strict_types=1);` en todo archivo nuevo, y migración gradual de los existentes.
- Enums para estados (cotización, mensaje, importación) en lugar de cadenas sueltas (hoy solo hay 1 enum).
- Una regla = un lugar. Antes de escribir una condición de negocio, buscar si ya existe en un servicio.
- Las consultas reutilizables van a *scopes* del modelo o a una clase de consulta; los controladores no encadenan `where`.
- Transacciones (`DB::transaction`) en el servicio, no en el controlador.
- Migraciones nunca se editan después de fusionadas; se añade una nueva.

### 5.2 Frontend
- Páginas = composición; la lógica con estado complejo va a *hooks* (`useQuotations`, `useInsightAlerts`) o a `useReducer` cuando hay más de ~6 estados relacionados.
- Un módulo `lib/<dominio>.ts` por dominio; los componentes no llaman a `fetch`.
- Tipos de la API generados desde el backend (ver D10) en lugar de escritos a mano.
- Componentes presentacionales en `components/`, sin conocer rutas ni permisos; los permisos se resuelven en `RouteGuards` y `navigation.ts`.
- Accesibilidad y diseño según `docs/12_SISTEMA_DE_DISENO.md`.

### 5.3 Pruebas (pirámide objetivo)
| Nivel | Qué cubre | Meta |
|---|---|---|
| Unitarias | Cálculos y reglas puras (RFM, aging, scoring, ventanas de 24 h, validación de archivo) | Crear `tests/Unit`; ≥ 30 % de las pruebas del backend |
| Feature | Contrato HTTP, permisos, alcance de datos | Las 65 actuales; una por endpoint nuevo |
| Contrato | Cada implementación de interfaz | Una por interfaz |
| Frontend | Comportamiento de la pantalla con mocks | Las 242 actuales |
| E2E (Playwright) | Flujos críticos: iniciar sesión, importar, cotizar, conversar | 5–8 flujos |
- Cobertura mínima en CI (informativa al inicio, bloqueante después de estabilizar).
- Toda corrección de error empieza con una prueba que falla.

### 5.4 Flujo de trabajo del equipo
- **Ramas**: `main` protegida; trabajo en `feature/<fase>-<tema>`, `fix/<tema>`, `docs/<tema>`; PR obligatorio con 1 revisión.
- **Commits**: mensajes en español, imperativo y con alcance (`Conversaciones: …`); un cambio lógico por commit.
- **PR**: plantilla con la lista de la sección 4, capturas si hay interfaz, riesgo y *rollback*.
- **Versionado**: etiquetas por fase (`v0.12.0`) y `CHANGELOG`.
- **Decisiones**: toda decisión de negocio queda en `docs/` (ya se hace); las técnicas importantes en ADR cortos (`docs/adr/NNN-titulo.md`).
- **Ajustes de la revisión**: siguen el registro `docs/23`.

### 5.5 Seguridad y operación
- Dependencias: `composer audit` y `npm audit` en CI; actualizaciones agrupadas mensuales.
- Secretos solo en variables de entorno / cifrados en BD; nunca en el repositorio.
- Datos de desarrollo (`DevDataSeeder`) bloqueados fuera de `local`.
- Registros estructurados con identificador de petición; alertas sobre colas fallidas y mensajes `failed`.
- Copia de seguridad y prueba de restauración de PostgreSQL documentadas.

## 6. Plan de adopción (por pasos, cada uno con aprobación)

| Paso | Contenido | Esfuerzo | Riesgo |
|---|---|---|---|
| A ✅ | **CI en GitHub Actions** (Pint, PHPStan, pruebas, typecheck, lint, test, build, audits) + plantilla de PR + `CONTRIBUTING.md` + actualizar `README` | Bajo | Nulo |
| B | `strict_types` en todos los archivos (automático con Pint `declare_strict_types`) y revisión de fallos | Bajo–medio | Bajo (las pruebas lo detectan) |
| C | PHPStan nivel 5 → 6 → 8, subiendo uno por vez sin *baseline* | Medio | Bajo |
| D | Mover consultas de los 10 controladores con `DB::` a servicios/scopes (empezando por Conversaciones, Notificaciones, Cotizaciones) | Medio | Medio (cubierto por pruebas Feature) |
| E | Crear `tests/Unit` con la lógica pura y empezar por RFM, aging, scoring, ventanas | Medio | Bajo |
| F | Policies para los recursos con alcance por registro (clientes, cotizaciones, conversaciones) | Medio | Medio (toca permisos: confirmar antes) |
| G | Dividir `DashboardService`, `SalesStatistics`, `CustomerInsights`, `SalesReportExporter` y los `ImportType` > 400 líneas | Medio–alto | Medio |
| H | Interfaces para puertos externos (WhatsApp, correo, webhook) y eliminar `app()`/`new` de la lógica | Medio | Bajo |
| I | `Resource` en todas las respuestas + generación de tipos TS (OpenAPI con Scribe/Scramble) | Medio | Bajo |
| J | Hooks para páginas con > 8 estados; Playwright con 5–8 flujos críticos | Medio–alto | Bajo |
| K | ADR, `CHANGELOG`, etiquetas, métricas y registros estructurados | Bajo | Nulo |

**Orden recomendado**: A → B → E → D → H → F → C → G → I → J → K. Primero lo que protege (CI, tipos estrictos, pruebas unitarias) y después lo que reordena el código; así cada refactor queda cubierto antes de hacerse.

## 7. Indicadores para medir la mejora

| Indicador | Hoy | Meta |
|---|---|---|
| Archivos con `strict_types` | 0 % | 100 % |
| Nivel PHPStan | 5 | 8 |
| Controladores con consultas directas | 10 | 0 |
| `app()`/`resolve()` fuera de proveedores | 39 | 0 |
| Pruebas unitarias | 0 | ≥ 150 |
| Policies | 0 | las de recursos con alcance por registro |
| Servicios > 300 líneas | 4 | 0 |
| Páginas con > 8 `useState` | 8 | 0 |
| CI obligatoria en PR | No | Sí |

## 8. Alcance y límites de este análisis
- Es un análisis **estático y estadístico** (conteos y lectura de muestras). No se midió cobertura de pruebas, rendimiento ni consultas N+1; eso entra en los pasos C y K.
- Ninguna de las propuestas cambia el comportamiento aprobado por el cliente: cada paso mantiene las pruebas en verde y se entrega con su reporte.

## 9. Registro de pasos del plan

### Paso A — CI, plantilla de PR y guía de contribución (2026-10-21)
Archivos: `.github/workflows/ci.yml`, `.github/PULL_REQUEST_TEMPLATE.md`, `.github/dependabot.yml`, `CONTRIBUTING.md`, y `README.md` actualizado (estado y enlace a la guía).
- **CI** con dos trabajos en paralelo: *backend* (PHP 8.5: Pint, Larastan, pruebas, `composer audit`) y *frontend* (Node 22: tipos, lint, pruebas, build, `npm audit`). Se ejecuta en cada PR y en `main`; cancela ejecuciones obsoletas. Las auditorías informan pero no bloquean (`continue-on-error`), hasta revisar el estado inicial.
- **Dependabot** mensual y agrupado (Composer, npm, Actions).
- **Pendiente por el responsable en GitHub** (no se puede hacer desde el repositorio): activar la protección de `main` — exigir PR, 1 revisión y los checks «Backend» y «Frontend» en verde. Hasta que se active, la CI avisa pero no impide fusionar.
- **Verificación**: los YAML se validaron sintácticamente en local; la primera ejecución real ocurre al subir. Si la CI falla por diferencias de entorno (extensiones de PHP, versión de Node), se corrige en un commit aparte.
