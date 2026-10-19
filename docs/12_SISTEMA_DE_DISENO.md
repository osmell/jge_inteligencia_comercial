# Sistema de diseño — JGE Inteligencia Comercial

Estilo corporativo sobrio para panel empresarial (Minimalism / Swiss): mucho espacio en blanco, alto contraste, jerarquía tipográfica clara y color de marca con moderación. Criterios tomados de la guía UI/UX Pro Max y adaptados a la paleta JGE aprobada en el contexto maestro.

## Principios
- **Marca con moderación:** azul JGE como color principal y de acciones; rojo JGE solo como acento (marcador de ítem activo, línea bajo el logo). Los colores de estado (verde, ámbar, rojo de error) son independientes de los de marca.
- **Estado nunca solo por color:** siempre punto o icono más texto («Activo», «Error: …»).
- **Accesibilidad (WCAG AA):** contraste ≥ 4.5:1 en texto, foco visible en todo control, objetivos táctiles ≥ 44 px (≥ 36 px en acciones secundarias de tabla), etiquetas visibles en todos los campos, errores junto al campo y anunciados (`role="alert"` con foco), diálogos con foco atrapado y devolución del foco, `prefers-reduced-motion` respetado.
- **Datos:** cifras con `tabular-nums`, códigos en fuente monoespaciada, tablas con encabezado fijo, estados de carga (esqueletos), vacíos y búsqueda.
- **Movimiento sutil:** transiciones de 150–180 ms, sin animar tamaño.

## Tokens (`frontend/src/index.css`, bloque `@theme`)
| Grupo | Tokens |
|---|---|
| Marca | `jge-blue #07579B`, `jge-blue-dark #054270`, `jge-blue-50/100`, `jge-navy #0A2F52` (barra lateral), `jge-red #E50918`, `jge-charcoal #353535` |
| Superficies | `canvas #F4F6F9`, `surface #FFFFFF`, `surface-muted #F8FAFC`, `line #E2E8F0`, `line-strong #CBD5E1` |
| Texto | `ink #0F172A`, `ink-muted #475569`, `ink-subtle #64748B` |
| Estado | `success #16803C`, `warning #B7791F`, `danger #C62828` (+ tonos `-50`), `info` |
| Tipografía | Fira Sans (interfaz) y Fira Code (códigos), autoalojadas con `@fontsource` (sin depender de Google Fonts) |

## Componentes (`frontend/src/components/`)
`ui.tsx`: `Button` (primary / secondary / ghost / danger, con estado de carga), `Card`, `PageHeader`, `Alert`, `FormError`, `Modal`, `TextField`, `SelectField`, `CheckField`, `SearchInput`, `StatusBadge`, `Badge`, `DataTable`, `TableSkeleton`, `EmptyRow`. `toast.tsx`: avisos de éxito. `Layout.tsx`: barra lateral por grupos con iconos (Lucide), barra superior y menú móvil. `Brand.tsx`: marca provisional.

## Pendientes de diseño
- Logo definitivo y validación de los colores exactos contra el archivo original (decisión #20).
- Modo oscuro: opcional según la decisión #20; los tokens están centralizados para añadirlo después.
