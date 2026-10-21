# Fase 10 — Dashboard inicial con indicadores esenciales

## Objetivo
Que la pantalla de inicio deje de ser una bienvenida y muestre, al entrar, lo esencial del negocio con **gráficos e información consolidada**, distinta según quién entra: **administración/gerencia**, **supervisor** y **vendedor**. Es una vista de lectura que reúne lo que ya existe (ventas, metas, cartera, cotizaciones, seguimiento, inventario, recomendaciones); no crea reglas de negocio nuevas ni cambia datos.

## Reglas que se mantienen
- Cada persona ve **solo lo que ya puede ver** en las pantallas de origen: mismas compañías, sucursales y permisos (el vendedor, sus clientes; el supervisor, su equipo; costos solo con su permiso). Un indicador sin permiso no aparece, no se muestra en cero.
- Cada cifra del dashboard **coincide con la de su pantalla de detalle** y enlaza a ella; el periodo y la fecha de actualización se muestran siempre.
- Sin datos suficientes, el gráfico lo explica en lugar de mostrar un vacío engañoso.
- Rendimiento: el dashboard se carga con pocas consultas agregadas por sección (cada sección carga y falla de forma independiente).
- Accesible y legible en teléfono; los gráficos tienen alternativa en tabla/texto y no dependen solo del color.

## Contenido propuesto (sujeto al cuestionario `19_PREGUNTAS_FASE_10_DASHBOARD.md`)
- **Administración/gerencia (consolidado de compañías):** venta neta del mes contra el mes anterior y contra el mismo mes del año anterior, tendencia diaria/mensual, cumplimiento de metas por vendedor, cartera por tramos y vencida, cotizaciones abiertas y por vencer, productos bajo mínimo, recomendaciones graves, principales clientes y productos, resumen por compañía/sucursal.
- **Supervisor (su equipo):** venta y avance de metas del equipo y de cada vendedor, clientes sin contacto reciente, acciones de seguimiento vencidas, cartera vencida de sus clientes, cotizaciones por vencer, recomendaciones de su equipo.
- **Vendedor (lo suyo):** su venta del mes y su meta, lo que le falta, su agenda de hoy y vencida, sus cotizaciones por vencer, saldos vencidos y promesas de pago de sus clientes, sus recomendaciones.

## Pasos propuestos
1. Cuestionario del responsable y definición exacta de indicadores por rol (sin tocar código).
2. Servicio de dashboard por rol: indicadores consolidados reutilizando los servicios existentes, con el alcance de cada persona; API por secciones.
3. Componentes de gráficos reutilizables (línea/área, barras, dona/avance, mini-tendencias) accesibles y con tabla alterna.
4. Dashboard de administración/gerencia.
5. Dashboard de supervisor.
6. Dashboard de vendedor.
7. Filtros de periodo y compañía, enlaces a detalle, estados vacíos y de error por sección, rendimiento y documentación.

## Criterios de aceptación
- Cada rol ve su dashboard al entrar; ninguno ve datos fuera de su alcance (probado por rol).
- Las cifras coinciden con las pantallas de detalle.
- Cada sección carga y falla de forma independiente; sin datos se explica.
- Pruebas automáticas de alcance por rol y de cálculo; revisión con datos reales.

## Paso 2 — Servicio y API del dashboard (hecho)
Supuestos tomados de las propuestas del cuestionario `19` (pendientes de confirmar): gerencia ve lo mismo que administración; periodo en curso por omisión (también mes anterior, trimestre y año); comparación contra el **mismo tramo** del mes/trimestre/año anterior y contra el mismo tramo del año anterior; proyección al cierre de metas.
- **API:** `GET /api/v1/dashboard` (perfil, secciones disponibles, compañías del ámbito, fecha de la última venta y de la última carga) y `GET /api/v1/dashboard/{sección}?period=month|prev_month|quarter|year&company_id=` (una sección por llamada, para que cada una cargue y falle por separado).
- **Perfiles:** `seller` (vendedor: solo sus ventas, clientes, cartera y cotizaciones, por los vendedores vinculados a su usuario), `team` (supervisor: los vendedores que supervisa) y `company` (administración, gerencia y demás: todo su ámbito de compañías). Un vendedor sin registro de vendedor vinculado ve **ceros, no todo**.
- **Secciones y permiso (el mismo de su pantalla de origen):** `sales` (venta neta, facturas, clientes, ticket promedio; contra el tramo anterior y el del año anterior; serie diaria y de 12 meses), `goals` (meta, avance, falta, proyección al cierre y filas por vendedor), `rankings` (5 principales clientes y productos; vendedores, salvo para el vendedor), `companies` (comparativo, solo si ve más de una compañía), `credit` (saldo, vencido, tramos, principales deudores, promesas de los próximos 7 días e incumplidas de 30), `quotations` (abiertas, por vencer en 3 días, vencidas sin cerrar, emitidas y convertidas en el periodo), `followup` (acciones vencidas y de hoy), `inventory` (agotados, bajo mínimo, sin rotación, sobre máximo) e `insights` (recomendaciones abiertas por gravedad, clientes en riesgo, caídas de venta).
- Reutiliza los servicios existentes (`SalesStatistics`, `SellerGoals`, `CreditPortfolio`, `PaymentPromises`, `FollowUpAgenda`, `InventoryRotation`); `SalesStatistics` acepta `seller_ids` y `CreditPortfolio::aging` un filtro de vendedores. No hay cálculos nuevos de negocio.
- Pendiente: valor del inventario (solo con permiso de costos) y clientes sin contacto reciente; se añaden si el responsable los confirma en el cuestionario.

## Paso 3 — Componentes de gráficos (hecho)
Se amplía `frontend/src/components/charts.tsx` (el `BarChart` y la `InlineBar` existentes no cambian) con componentes sin librerías externas, en SVG, que usan los colores del sistema de diseño:
- **`LineChart`:** hasta 3 series comparadas por posición (día 1 del periodo contra día 1 del anterior). La primera lleva área y trazo continuo; las demás, trazos distintos (guiones) para no depender solo del color; leyenda con el nombre de cada serie; cada punto trae su valor como texto (`<title>`).
- **`Sparkline`:** mini-tendencia para tarjetas de indicador; el valor y el cambio se escriben al lado.
- **`ProgressBar`:** avance contra una meta, con marca de referencia opcional (esperado a la fecha o proyección), `role="progressbar"` y el estado dicho con **texto** (Cumplida / Cerca / Lejos) además del color; nunca se dibuja por encima del 100 %.
- **`DonutChart`:** reparto de un total (p. ej. cartera por tramos) con leyenda que da valor y porcentaje de cada parte; sin total, muestra solo el fondo.
- **`ChartData`:** tabla alterna plegada («Ver los datos en tabla») que acompaña a cada gráfico con las cifras exactas.
- **`goalTone`** (`lib/tone.ts`): los umbrales ya usados en Metas (≥100 % cumplida, ≥75 % cerca, menos: lejos).
Todos son `role="img"` con descripción; sin datos no fallan. Probados en `charts.test.tsx`.

## Paso 4 — Dashboard de administración y gerencia (hecho)
- La pantalla de inicio (`/`) pasa a ser el dashboard: saludo, alcance de la persona, fecha de la última venta y de la última carga, selector de **periodo** (mes en curso, mes anterior, trimestre, año), selector de **compañía** (si ve más de una) y botón **Actualizar**. Los accesos rápidos quedan al final.
- Cada sección es un bloque independiente (`components/DashboardSections.tsx`) que pide su propia API, muestra «Cargando…», y si falla enseña su error con **Reintentar** sin afectar a las demás; cada una enlaza a su pantalla de detalle. La pantalla solo pide las secciones que la API indica para esa persona (mismo mecanismo que usarán supervisor y vendedor).
- **Ventas:** venta neta con cambio contra el tramo anterior y contra el año anterior, facturas, clientes, ticket promedio; línea de venta diaria del periodo contra el tramo anterior (día n contra día n, con ceros en días sin venta) y barras de los últimos 12 meses, cada una con su tabla alterna. **Metas:** barra de cumplimiento del conjunto con la proyección al cierre como marca, y una barra por vendedor con estado en texto. **Cartera:** saldo, vencido, promesas, anillo por antigüedad y principales deudores. **Cotizaciones**, **Seguimiento**, **Inventario** y **Recomendaciones** como tarjetas con color y texto. **Principales del periodo** (clientes, productos, vendedores) y **Por compañía** (tabla comparativa).
- API: se añadió `daily_previous` a la sección de ventas para la comparación diaria.
- El diseño por rol (supervisor y vendedor) usa los mismos bloques; los pasos 5 y 6 ajustan lo propio de cada uno (p. ej. clientes sin contacto, agenda del día).

## Paso 5 — Dashboard de supervisor (hecho)
- Mismo dashboard, con el alcance del supervisor: solo los vendedores que supervisa (los vinculados con «supervisor» en su ficha de vendedor). Se muestra «Tu equipo: …» con sus nombres y, si no tiene vendedores asignados, un aviso en vez de cifras engañosas (todo en cero).
- Metas y ranking de vendedores salen del equipo; la cartera, las cotizaciones y la venta, de los clientes y facturas de esos vendedores. Sin inventario ni recomendaciones salvo que se le concedan esos permisos.
- **Seguimiento** ahora trae, para todos los perfiles, el indicador de **clientes inactivos o perdidos sin contacto en 30 días** (que aceptan comunicaciones) y los 5 primeros con enlace a su ficha, además de las acciones vencidas y de hoy. La API de equipo se expone en `GET /dashboard` como `team`.

## Paso 6 — Dashboard de vendedor (hecho)
- Mismo dashboard, solo con lo del vendedor (sus ventas, clientes, facturas de crédito y cotizaciones), con **títulos y orden propios**: primero **Tu agenda** (acciones vencidas y de hoy con enlace al cliente, y los clientes por recuperar), luego **Tu meta del mes**, **Tus ventas**, **Tus clientes por cobrar**, **Tus cotizaciones** y las recomendaciones de sus clientes. No ve el ranking de vendedores ni el desglose por vendedor de las metas.
- **Meta:** barra de avance con la proyección al cierre y cuánto necesita vender **por día** en los días que quedan del mes (falta ÷ días restantes, hoy incluido; solo en el mes en curso).
- **Cobranza:** además del saldo y los tramos, **promesas de pago por atender** (las que vencen en 7 días y las incumplidas de los últimos 30), con enlace al cliente; para los demás perfiles se calcula con su mismo alcance.
- API: `goals.totals.days_left` y `needed_per_day`, `followup.actions` (hasta 5, vencidas primero) y `credit.promises.attention`.
- Con esto los tres perfiles están cubiertos; falta el paso 7 (pulido: filtros, rendimiento y documentación) y que el responsable confirme el cuestionario `19`.

## Paso 7 — Pulido, coherencia y rendimiento (hecho)
- **Coherencia con el detalle:** una prueba automática compara el dashboard contra las pantallas de origen (`/sales/summary`, `/sales/goals`, `/credit/aging`): venta neta, facturas, ticket, cambio contra el tramo anterior, meta, avance, cumplimiento, saldo, tramos y principales deudores son idénticos.
- **Rendimiento medido** en la base de desarrollo (≈17.400 líneas de venta, 291 clientes, administrador): cada sección tarda entre 4 y 150 ms (ventas ≈120–150 ms con 8–11 consultas; la más pesada, el ranking del año, ≈110 ms); las nueve secciones se piden por separado y en paralelo desde el navegador. Con el volumen real (años de historial y más clientes) conviene volver a medirlo; si hiciera falta, el siguiente paso sería guardar el cálculo del mes cerrado.
- **Estados vacíos y de error:** sin ventas en el periodo se explica (y desde cuándo hay historial); una sección que falla muestra su error con «Reintentar» sin afectar a las demás.
- **Teléfono:** tarjetas en una columna, selectores que se acomodan en filas, gráficos que escalan al ancho y tablas alternas plegadas.
- **Pendiente de negocio:** respuestas al cuestionario `19` y validación con datos reales (lista de comprobación en `docs/14`). Fuera de alcance por ahora: exportar el dashboard, reordenar o personalizar secciones, valor del inventario (requiere permiso de costos).
