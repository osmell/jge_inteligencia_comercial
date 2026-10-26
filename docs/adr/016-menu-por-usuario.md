# ADR-016 — Menú por usuario: ocultar y bloquear

- **Estado**: Aceptado · **Fecha**: 2026-10-26 · **Relacionado**: `docs/28_SEGURIDAD.md`, ADR-005 (autorización en capas)

## Contexto
La administración pidió decidir qué opciones del menú ve cada persona que no es administradora, y que un grupo sin opciones visibles desaparezca. «Roles y permisos» ya decide qué puede hacer un **rol**; faltaba afinar por **persona**. Ocultar solo en la interfaz no es seguridad (la dirección se puede escribir), y las pantallas **comparten datos** (la ficha de un cliente muestra su crédito, cotizaciones y alertas; Crédito usa los mismos datos).

## Decisión
- **Solo quita, nunca da**: una opción aparece si el rol la permite **y** la administración no la ocultó. `users.hidden_menu` (JSON, nulo = ve todo lo de su rol), así que desplegarlo no quita acceso a nadie.
- **Por usuario, solo administradores**: la pantalla y la API (`/admin/menu-access`) exigen el rol Administrador (`EnsureAdministrator`), no un permiso: ningún otro rol puede recibirlo desde «Roles y permisos». Se comprueba antes de resolver el modelo (403, nunca 404). Los administradores no se pueden restringir y su selección guardada se ignora.
- **Ocultar y bloquear en el servidor**: `MenuCatalog` asigna cada ruta de la API a las opciones que la usan (prefijo más largo por segmentos). `EnsureMenuOptionIsVisible` responde 403 `menu_option_hidden` cuando **todas** las opciones dueñas de una ruta están ocultas. Un dato compartido sigue disponible mientras otra opción visible lo use; esa es la regla de negocio acordada con el cliente.
- **Siempre disponibles** (excepciones declaradas): sesión, Inicio, notificaciones, tiempo real, listas de compañías y sucursales, la insignia de mensajes sin leer y la propia pantalla de administración.
- **La interfaz** deriva su menú del mismo catálogo: las llaves de `navigation.ts` y de `MenuCatalog::OPTIONS` deben coincidir (lo verifica una prueba); oculta el elemento, lo quita de la barra de comandos y redirige al inicio si se escribe su dirección.
- **Auditoría**: cada cambio queda como `user.updated` con lo anterior y lo nuevo.

## Consecuencias
- Una ruta nueva sin dueño ni excepción **hace fallar** `MenuCatalogTest`: no puede quedar sin bloqueo por olvido.
- Por la regla de datos compartidos, ocultar solo «Clientes» no bloquea la lista general de clientes si «Cotizaciones» sigue visible. Es lo esperado y está documentado.
- Una opción nueva exige agregarla en tres sitios (catálogo del servidor con sus rutas, `navigation.ts` y, si procede, el permiso); las pruebas lo vigilan.
- El menú de la persona afectada se actualiza al recargar o iniciar sesión de nuevo; el servidor aplica el cambio en la siguiente petición.

## Alternativas descartadas
- **Solo ocultar en la interfaz**: no es una restricción real.
- **Un permiso nuevo por opción y por persona**: duplicaría «Roles y permisos» y permitiría dar lo que el rol no tiene.
- **Bloquear cada ruta por una sola opción**: rompería pantallas que comparten datos (la ficha del cliente sin su crédito).
