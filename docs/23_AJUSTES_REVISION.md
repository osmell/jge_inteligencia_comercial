# Ajustes de la revisión pantalla por pantalla

Registro de los ajustes que pide el responsable durante la revisión de cada ventana y opción de la solución. Se trabajan **uno por uno**: cada ajuste se implementa con su prueba, se reporta y **queda pendiente de aprobación** hasta que el responsable confirme que el comportamiento es el que requiere el cliente.

**Estados.** `Pendiente` (pedido, sin hacer) · `En curso` · `Implementado — por aprobar` · `Aprobado` · `Cambia` (el responsable pidió otro comportamiento; se vuelve a trabajar) · `Descartado`.

**Cómo se trabaja cada ajuste.**
1. El responsable describe el ajuste (pantalla, qué ve, qué espera).
2. Se registra aquí con un número, antes de tocar código.
3. Se implementa con prueba, se corren todas las pruebas, se hace commit y push.
4. Se reporta y el estado pasa a `Implementado — por aprobar`.
5. El responsable aprueba (o pide cambios). Solo entonces pasa a `Aprobado`.
Si un ajuste toca una regla de negocio (permisos, datos que se mueven o se borran), se confirma antes de implementarlo.

## Registro

| N.º | Pantalla / área | Ajuste pedido | Estado | Commit | Aprobación del responsable |
|---|---|---|---|---|---|
| 1 | Inicio (dashboard) y encabezado | Quitar los «Accesos rápidos» del dashboard y reemplazarlos por una barra de texto en el encabezado que funciona como línea de comandos con `/` (Fase 12, `docs/22`). | Implementado — por aprobar | `a2fee9f` | — |
| 2 | Administración → Sucursales | Permitir mover una sucursal a otra compañía cuando no tiene datos relacionados; si los tiene, mostrar un mensaje claro de cuáles la impiden. | Implementado — por aprobar | `3549843` | — |

### Detalle de los ajustes

**1. Barra de comandos.** Tecla `/` enfoca la barra del encabezado; se escribe el nombre de una pantalla (con o sin `/`, sin distinguir acentos), Enter va a ella; solo muestra lo que el rol puede ver. Decisiones tomadas por mí que el responsable puede cambiar: búsqueda solo de pantallas (no de clientes ni acciones); en pantallas chicas la barra reemplaza al título de la pantalla actual.

**2. Mover sucursal de compañía.** Bloquea si hay documentos/líneas de venta, facturas de crédito, clientes, movimientos/existencias/recepciones/faltantes de inventario, usuarios con acceso a la sucursal o vendedores asignados (incluido el vendedor «Tienda» si tiene datos). Sin bloqueos se mueve con su vendedor «Tienda» (el anterior queda inactivo). Decisión mía a confirmar: los *usuarios con acceso* también bloquean (alternativa: quitarles el acceso automáticamente).
