# Propuesta del paso F — Policies de autorización por registro

> Estado: **IMPLEMENTADO (2026-10-21)** con las decisiones D1 a D7 en la opción ★ (sin cambios de comportamiento), según la respuesta del responsable «Todas ★». Ver sección 7.
> Fecha: 2026-10-21 · Plan general: `docs/24_ANALISIS_CODIGO_Y_GUIA_DESARROLLO.md` (debilidad D6).

## 1. Qué problema resuelve
Hoy «¿puede ESTA persona hacer ESTO con ESTE registro?» se decide en cuatro lugares distintos:

| Capa | Ejemplo | Dónde vive |
|---|---|---|
| Permiso del rol | `permission:customers.view` | middleware en `routes/api.php` (40 usos) |
| Qué registros ve | vendedor solo ve sus clientes | *global scope* de `Customer` (usa `Auth::user()`), `ConversationAccess::visible`, `DataScope` |
| Regla sobre un registro | «solo quien registró el contacto lo corrige» | `abort_unless(...)` dentro de 11 controladores |
| Rol escrito a mano | `role->slug === Role::SUPERVISOR_VENTAS` | `ConversationAccess`, `ConversationToolsController`, `InsightAnalysisController` |

Consecuencias: la misma regla aparece en varios sitios (p. ej. «puede escribir en la conversación» se verifica en 5 métodos), es difícil responder «¿qué puede hacer un supervisor?» sin leer 11 archivos, y no hay una prueba única por regla.

## 2. Qué es una Policy y qué NO cambia
- Una **Policy** es una clase por modelo con un método por acción (`view`, `update`, `reply`…). Los controladores llaman `$this->authorize('reply', $conversation)` o `Gate::allows(...)`, y el frontend recibe las mismas decisiones (`can_reply`, `can_edit`) de la misma fuente.
- **No reemplaza** el middleware de permisos de ruta (sigue siendo la primera barrera) **ni** los filtros de lo que cada rol ve en las listas (`DataScope`, scope de clientes, `ConversationAccess::visible`): una Policy decide sobre UN registro, el filtro decide qué entra en la lista. Ambos usarán la misma regla para no divergir.
- **Es un refactor**: con la propuesta tal cual, el comportamiento actual no cambia (las 664 pruebas deben pasar sin modificarse). Los cambios de comportamiento solo ocurren si el responsable decide uno en la sección 4.

## 3. Policies propuestas (reglas tal como están hoy)

### 3.1 `WhatsappConversationPolicy`
| Acción | Regla actual (de dónde sale) |
|---|---|
| `view` | Tiene `conversations.view` y la conversación está en su alcance: Vendedor → clientes de sus vendedores; Supervisor → clientes de los vendedores que supervisa y las «sin asignar» de sus compañías; Administración/Gerencia → su ámbito (`ConversationAccess::visible`) |
| `reply` | `conversations.reply` + rol Vendedor o Supervisor + conversación con cliente asignado + `view` (`ConversationAccess::canReply`, repetido en 5 métodos) |
| `assign` | Rol Supervisor + `conversations.reply` + conversación sin cliente (`ConversationToolsController::unassigned`) |
| `label`, `note`, `sendQuotation` | Igual que `reply` |

### 3.2 `QuotationPolicy`
| Acción | Regla actual |
|---|---|
| `view` | Puede ver al cliente de la cotización (el scope de clientes ya oculta las ajenas al Vendedor); permiso `customers.view` |
| `update`, `changeStatus`, `attach`, `detach`, `link` | Permiso `interactions.manage` y ver al cliente. **No** se comprueba quién creó la cotización (ver decisión D2) |
| `unlink` | Permiso `customers.manage` |
| `download` | Solo `customers.view` + ver al cliente |

### 3.3 `CustomerInteractionPolicy`
| Acción | Regla actual |
|---|---|
| `update` | Quien lo registró **o** quien tiene `customers.manage` (`CustomerInteractionController::canEdit`) |
| `complete` | Igual que `update` |

### 3.4 `CustomerPolicy`
| Acción | Regla actual |
|---|---|
| `view` | Permiso `customers.view` y que el scope lo deje ver (Vendedor: solo los de sus vendedores; los demás: su ámbito de compañías/sucursales) |
| `manage` (tipo, datos, vendedor, facturador) | Permiso `customers.manage` y el cliente/vendedor dentro de las compañías con acceso completo |

### 3.5 `QuickReplyPolicy`
`update`: solo la persona dueña. `share`: solo el rol Supervisor.

## 4. Decisiones que necesito del responsable
Cada una es un punto donde hoy el comportamiento es **ambiguo o discutible**. La opción marcada (★) es la que mantiene lo actual.

| # | Pregunta | Opciones |
|---|---|---|
| **D1** | Hoy los roles «Vendedor» y «Supervisor» están escritos en el código para decidir quién puede escribir a clientes y asignar números. ¿Se deja así o se vuelve configurable? | ★ A. Se deja por rol (administración y gerencia nunca escriben, como se acordó en Fase 11). B. Dos permisos nuevos (`conversations.write` y `conversations.assign`) que el Administrador concede por rol. |
| **D2** | Cotizaciones: hoy **cualquier** persona con `interactions.manage` que vea al cliente puede editar, cambiar el estado, adjuntar o vincular cualquier cotización de ese cliente, aunque la haya creado otra. ¿Es lo que quiere el cliente? | ★ A. Sí, cualquiera del equipo que vea al cliente. B. Solo quien la creó, su vendedor, su supervisor y quien tenga `customers.manage`. |
| **D3** | Un Vendedor ve las cotizaciones de **sus** clientes aunque las haya creado un supervisor. Si en D2 elige B, ¿el vendedor responsable del cliente puede modificarlas? | ★ A. Sí. B. No, solo ver. |
| **D4** | Contactos de seguimiento: «solo quien registró el contacto lo corrige, o quien tiene `customers.manage`». ¿Debe el supervisor poder corregir los de su equipo? | ★ A. No (como hoy). B. Sí. |
| **D5** | Descargar el adjunto de una cotización solo pide `customers.view`. ¿Debe pedir también `interactions.manage` (como ver documentos)? | ★ A. Se deja. B. Se exige. |
| **D6** | El scope de clientes depende de `Auth::user()`. En comandos de consola y trabajos en cola no hay usuario autenticado y el scope no filtra. Hoy es inocuo (esos procesos no devuelven datos a una persona), pero es frágil. ¿Se endurece? | ★ A. Se deja y se documenta. B. Se pasa el usuario explícitamente en los procesos que muestran datos a una persona. |
| **D7** | Mensajes de rechazo: hoy varios dicen distinto lo mismo («No puedes escribir…», «No tienes permiso…»). ¿Se unifican por Policy? | ★ A. Se unifican por acción. B. Se conservan tal cual. |

## 5. Plan de implementación (cuando se apruebe)
1. Policies con la **tabla de arriba** y una **prueba unitaria por regla** (rol × situación → permitido/denegado); esas pruebas son la especificación viva de los permisos.
2. Cambiar los controladores y `ConversationAccess::canReply` para usar las Policies; `visible()` sigue siendo el filtro de listas.
3. Que `can_reply`, `can_edit` y `can_move` del frontend salgan de la Policy.
4. Verificación: las 664 pruebas actuales pasan **sin modificarse** (si D1–D7 se mantienen en ★). Las decisiones que cambien comportamiento se implementan después, una por una, con su prueba y su registro en `docs/23`.
5. Regla de arquitectura nueva: ningún controlador compara `role->slug` ni usa `abort_unless` con reglas de acceso a un registro.

Esfuerzo: medio. Riesgo: medio si se mantienen las opciones ★ (refactor cubierto por pruebas); mayor si se elige B en D1–D4 porque cambia lo que cada rol puede hacer.

## 6. Qué necesito para empezar
Responder D1 a D7 (basta con «todas ★» para el refactor sin cambios de comportamiento).

## 7. Resultado de la implementación
Decisiones aplicadas: **D1–D7 = ★** (se mantiene el comportamiento actual; D7 unifica el mensaje de rechazo para escribir y para agregar notas).

| Policy | Acciones | Reemplaza |
|---|---|---|
| `WhatsappConversationPolicy` | `view`, `reply`, `assign` | `ConversationAccess::canReply` (5 verificaciones repetidas) y la regla de asignar de `ConversationToolsController` |
| `QuotationPolicy` | `view`, `manage`, `unlink`, `download` | Verificaciones dispersas en `QuotationController` (ahora `find($id, $ability)` busca en el alcance y autoriza) |
| `CustomerInteractionPolicy` | `update` | `CustomerInteractionController::canEdit` (también alimenta `can_edit`) |
| `CustomerPolicy` | `view`, `manage` | Edición de datos y asignación de vendedor/facturador |
| `SellerPolicy` / `OperatorPolicy` | `update` (404 fuera del acceso completo) | Los `abort_if` con `fullCompanyIds` de `CustomerAssignmentController` |
| `QuickReplyPolicy` | `share` | Comparación de rol en `ConversationToolsController` |
| Gate `view-company-analysis` | — | Comparación de rol en `InsightAnalysisController` |

- Los controladores usan `$this->authorize(...)` (el `Controller` base ahora incluye `AuthorizesRequests`); el frontend recibe `can_reply` y `can_edit` de las mismas Policies.
- El middleware de permisos de ruta y los filtros de listas (`ConversationAccess::visible`, scope de clientes, `DataScope`) **no cambian**.
- `tests/Feature/PoliciesTest` (10 pruebas) es la especificación viva: cada regla, por rol y situación. Las 664 pruebas anteriores pasan **sin modificarse**. Total: 675.
- Nueva regla en `ArchitectureTest`: ningún controlador (fuera de Admin) decide acceso comparando `role->slug`.
- Se creó el modelo `QuickReply` solo como ancla de su Policy; las consultas siguen en el servicio `QuickReplies`.
