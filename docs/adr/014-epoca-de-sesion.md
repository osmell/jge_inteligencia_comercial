# ADR-014 — Época de sesión por usuario

- **Estado**: Aceptado · **Fecha**: 2026-10-23 · **Relacionado**: `docs/28_SEGURIDAD.md` (riesgo R3), ADR-013

## Contexto
Con un servidor de varios procesos, una petición que ya estaba en curso (la pantalla consulta avisos cada pocos segundos) puede volver a guardar la sesión **después** de que el cierre de sesión la destruyó. Resultado: la persona cierra sesión y la sesión sigue viva. Lo reprodujimos siempre en una instalación de pruebas de 4 procesos. Además, cambiar la clave o desactivar una cuenta no cerraba las sesiones ya abiertas.

## Decisión
- `users.session_epoch` (entero, empieza en 0). Cada sesión guarda la época con la que se abrió (al iniciar sesión).
- El middleware `EnsureSessionIsCurrent` compara, en **cada** petición con sesión, la época de la sesión con la actual del usuario; si no coinciden responde 401 con el código `session_revoked` y cierra esa sesión. Se evalúa antes que la resolución de modelos y que los permisos.
- La época sube al **cerrar sesión**, **cambiar la clave**, **restablecerla** (administración) y **desactivar** la cuenta. Quien cambia su propia clave conserva la sesión con la época nueva.
- Una sesión anterior a este control (sin época) vale como época 0: sigue sirviendo hasta que la época suba.
- La interfaz escucha cualquier 401 y devuelve a la pantalla de ingreso.

## Consecuencias
- Aunque una petición en curso reviva la sesión, trae la época vieja y la siguiente petición se rechaza: el cierre de sesión **siempre** cierra.
- **Cerrar sesión en un equipo cierra todos los equipos de esa persona.** Es deliberado (estricto y simple); si algún día se quisiera cerrar solo el equipo actual, hay que llevar una época por sesión.
- Desactivar una cuenta y volver a activarla no resucita sus sesiones anteriores.
- Una prueba recorre las las rutas autenticadas con una sesión revocada y exige 401 en todas (salvo el cierre de sesión, que debe poder ejecutarse siempre).
- Costo: una columna y una comparación por petición (el usuario ya se carga en cada una).

## Alternativas descartadas
Reducir solo `SESSION_LIFETIME` (no cierra nada: acorta la ventana) y mover las sesiones a un almacén con bloqueo (cambia la infraestructura y no arregla cambiar la clave ni desactivar).
