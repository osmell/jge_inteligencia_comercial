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
| Marca | `jge-blue #00508C`, `jge-blue-dark #003D6B`, `jge-blue-50/100`, `jge-navy #072C4D` (barra lateral), `jge-red #B01C14`, `jge-charcoal #353535` (colores muestreados de `logo_large.png`) |
| Superficies | `canvas #F4F6F9`, `surface #FFFFFF`, `surface-muted #F8FAFC`, `line #E2E8F0`, `line-strong #CBD5E1` |
| Texto | `ink #0F172A`, `ink-muted #475569`, `ink-subtle #64748B` |
| Estado | `success #16803C`, `warning #B7791F`, `danger #C62828` (+ tonos `-50`), `info` |
| Tipografía | Fira Sans (interfaz) y Fira Code (códigos), autoalojadas con `@fontsource` (sin depender de Google Fonts) |

## Componentes (`frontend/src/components/`)
`ui.tsx`: `Button` (primary / secondary / ghost / danger, con estado de carga), `Card`, `PageHeader`, `Alert`, `FormError`, `Modal`, `TextField`, `SelectField`, `CheckField`, `SearchInput`, `StatusBadge`, `Badge`, `DataTable`, `Th` (encabezado con «i» opcional), `Stat` (tarjeta de un indicador con «i» opcional), `TableSkeleton`, `EmptyRow`. `InfoTip.tsx`: la «i» que explica un dato; el texto sale del glosario `lib/glossary.ts` (clave) o se escribe en la pantalla (`{ text, formula? }`). La fórmula solo se pone si el dato es calculado y se escribe con palabras del negocio. El globo va en el `body` con posición fija para no recortarse ni cambiar el tamaño de la página. `toast.tsx`: avisos de éxito. `Layout.tsx`: barra lateral por grupos con iconos (Lucide), barra superior y menú móvil. `Brand.tsx`: `BrandLogo` (logo completo, sobre fondo claro), `BrandEmblem` (círculo rojo, espacios compactos) y `BrandPlate` (logo sobre placa blanca para fondos oscuros).

## Logos
Originales en `branding/originals/`. Derivados en `frontend/src/assets/brand/` (`logo-full.png` recortado y `logo-emblem.png` cuadrado) e iconos de pestaña en `frontend/public/` y `backend/public/favicon.ico`. El logo azul y rojo no se lee sobre azul oscuro: en la barra lateral y el login se coloca sobre una placa blanca.

## Pendientes de diseño
- Confirmar con JGE qué tono es oficial: el de `logo_large.png` (rojo `#B01C14`, azul `#00508C`, usado hoy) o el de las versiones 2024 (`#E00410`, `#00549C`). Cambiarlo son los tokens de `index.css`.
- Versión del logo en blanco o monocromática para fondos oscuros, si JGE la tiene (evitaría la placa blanca).
- Modo oscuro: opcional según la decisión #20; los tokens están centralizados para añadirlo después.

## Explicación de los datos («i»)
- Toda columna de tabla o tarjeta de indicador que sea **calculada, de estado o de significado no evidente** lleva `Th` o `Stat` con la propiedad `info`; las columnas obvias (nombre, correo, teléfono) no la llevan.
- El texto va en `lib/glossary.ts` si el dato se repite en varias pantallas (por ejemplo «Venta neta») y en la propia pantalla (`info={{ text, formula }}`) si es propio de ella.
- Una explicación describe lo que el sistema **hace de verdad**: se verifica contra el código del servicio o el documento de la fase antes de escribirla. La fórmula solo va en datos calculados y se escribe con palabras del negocio.
- Una cadena suelta no sirve como `info`: o es una clave del glosario o es un objeto `{ text, formula? }` (el compilador lo exige).
- La prueba de navegador `e2e/tests/13-info-tooltips.spec.ts` abre las «i» de cada pantalla y comprueba que el globo queda dentro de la ventana y que no cambia el ancho de la página. Una pantalla nueva con datos se agrega a su lista `SCREENS`.
