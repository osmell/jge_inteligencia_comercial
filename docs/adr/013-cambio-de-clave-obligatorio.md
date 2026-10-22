# ADR-013 — Cambio de clave obligatorio con clave temporal

- **Estado**: Aceptado · **Fecha**: 2026-10-22 · **Relacionado**: `docs/28_SEGURIDAD.md` (riesgo R2), ADR-005

## Contexto
Las cuentas las crea administración con una clave que le comunica a la persona por un canal informal. Esa clave la conoce más de una persona y debe dejar de ser válida como credencial duradera. No había forma de que cada quien cambiara su propia clave.

## Decisión
- Una clave que **fija otra persona** (alta de usuario o restablecimiento por administración) es **temporal**: `users.must_change_password = true`. Quien se pone su propia clave, o la cambia por sí misma, queda con `false`; las cuentas de desarrollo y las existentes no se obligan.
- La regla la aplica **el servidor**: el middleware `EnsurePasswordIsCurrent` responde 403 con el código `password_change_required` a todo, salvo ver el perfil (`GET /auth/me`), cambiar la clave (`POST /auth/password`) y salir. Se evalúa antes que los permisos y que la resolución de modelos, y también cubre los canales de tiempo real. La interfaz solo reacciona a ese estado (pantalla `/cambiar-clave` sin menú).
- `PasswordChanger` (servicio) cambia la clave: exige la clave actual aunque la sesión esté abierta, la rechaza si es igual a la actual o al nombre de usuario, **limita los intentos** (5 por ventana) y deja el cambio en Auditoría sin guardar ninguna clave. Las reglas de contraseña viven en un solo lugar (`PasswordPolicy`).
- Al cambiarla se regenera la sesión. Los restablecimientos por administración quedan en Auditoría (`user.password_reset`).

## Consecuencias
- Una clave temporal robada o compartida solo sirve para entrar a crear la clave propia, y solo hasta que la persona lo haga.
- La clave de un restablecimiento por administración vuelve a ser temporal: quien la fijó no queda con una credencial válida a largo plazo.
- Las claves de las cuentas ya existentes no se invalidan (hacerlo bloquearía a todos); administración puede restablecerlas cuando lo decida.
- **No cierra las otras sesiones abiertas** al cambiar la clave: sigue pendiente (ver `docs/28`, R3).
- Una prueba recorre las 192 rutas con una cuenta de clave temporal y exige 403 con el código en todas salvo las tres permitidas (`AccessMatrixTest`).

## Alternativas descartadas
Caducidad periódica de claves (genera claves previsibles y soporte) y obligar el cambio solo en la interfaz (se salta llamando a la API directamente).
