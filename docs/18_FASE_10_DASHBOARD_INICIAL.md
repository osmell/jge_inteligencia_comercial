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
