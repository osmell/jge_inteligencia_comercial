# Fase 12 — Barra de comandos en el encabezado

**Objetivo.** Quitar los «Accesos rápidos» (tarjetas) del dashboard de inicio y reemplazarlos por una barra de texto en el encabezado, al estilo de una línea de comandos: se presiona `/`, se escribe el nombre de una pantalla y Enter va a ella.

**Comportamiento.**
- La tecla `/` enfoca la barra desde cualquier pantalla, salvo cuando la persona está escribiendo en un campo (ahí `/` es un carácter normal).
- Se escribe con o sin la barra inicial (`/ventas` o `ventas`); no distingue mayúsculas ni acentos (`cotizacion` encuentra «Cotizaciones»). Busca en el nombre y en la ruta de cada pantalla; las que empiezan por lo escrito salen primero.
- Flechas arriba/abajo eligen, Enter va a la elegida, clic también, Escape limpia y cierra. Sin coincidencias lo dice y Enter no hace nada.
- Solo ofrece las pantallas que la persona puede ver según sus permisos (la misma regla que el menú lateral); incluye el grupo de cada pantalla como pista.
- Accesible: es un `combobox` con lista `listbox`, etiqueta para lectores de pantalla y `aria-activedescendant`.
- En pantallas chicas la barra ocupa el espacio del título de la pantalla actual, que se oculta.

**Dashboard.** Se eliminó el bloque «Accesos rápidos». El aviso para roles sin indicadores ahora apunta a la barra del encabezado.

**Archivos.** `frontend/src/components/CommandBar.tsx`, `Layout.tsx` (encabezado), `HomePage.tsx`, pruebas `commandbar.test.tsx` y `dashboard.test.tsx`.

**Fuera de alcance (posibles mejoras).** Buscar clientes, cotizaciones o conversaciones desde la misma barra; acciones («nueva cotización»); recordar las pantallas más usadas.
