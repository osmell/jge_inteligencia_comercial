# Operación y monitoreo

Guía corta para quien despliega y vigila el sistema. Las decisiones de fondo están en `docs/adr`.

## 1. Procesos que deben estar corriendo
| Proceso | Para qué | Si se cae |
|---|---|---|
| `backend` (API) | Atiende la aplicación | No hay servicio |
| `queue` (`php artisan queue:work`) | Validar e importar archivos, enviar WhatsApp y correo, descargar adjuntos | Los lotes se quedan «En cola»; los mensajes quedan sin enviar |
| Programador (`php artisan schedule:work` o cron cada minuto) | Automatizaciones, alerta de clientes esperando, lotes atascados | No hay avisos ni recálculos nocturnos |
| `reverb` | Tiempo real (conversaciones y campana) | La pantalla sigue funcionando por consulta periódica, con retraso |
| PostgreSQL (VM dedicada) | Datos | No hay servicio |

## 2. Estado en una sola orden
```bash
docker compose exec backend php artisan ops:status            # lectura humana
docker compose exec backend php artisan ops:status --json     # para un monitor
```
Indicadores: base de datos, trabajos fallidos (`failed_jobs`), cola pendiente y, en las últimas 24 h, mensajes de WhatsApp, webhooks y automatizaciones fallidos. Sale con **código 1** si algo pasa su umbral (`--max-failed-jobs`, `--max-queue`, `--max-failures`, `--hours`), así que sirve tal cual para un cron o un monitor externo. Además existe `GET /up` (salud básica de la aplicación).

## 3. Registros
- Cada petición de la API lleva un identificador (`X-Request-Id`, que el frontend o un proxy pueden enviar; si no, se genera). Está en la cabecera de la respuesta y en **todos los registros** de esa petición. Para investigar un error que reporta una persona: pedir el identificador (o buscarlo en la pestaña Red del navegador) y buscarlo en el registro.
- En producción usar `LOG_CHANNEL=json`: una línea JSON por registro, con `request_id` y, en errores, `user_id`. Recogerlos con el agregador que use la infraestructura (stderr del contenedor).
- Un trabajo en cola que agota sus reintentos escribe `queue.job_failed` con el trabajo, la cola, los intentos y el mensaje, y queda en `failed_jobs` (`php artisan queue:failed`, `queue:retry`).
- La **Auditoría** de la aplicación (pantalla del Administrador) es aparte: registra qué hizo cada persona, no fallos técnicos.
- Nunca se registran contraseñas, tokens ni secretos (se anotan como «cambiado»).

## 4. Alertas sugeridas
| Señal | Cómo | Acción |
|---|---|---|
| `ops:status` con código 1 | Cron cada 5–15 min y aviso a quien opera | Ver cuál indicador falló |
| `queue.job_failed` en el registro | Alerta del agregador | Revisar `queue:failed` y reintentar |
| Lotes «En cola» más de unos minutos | Visible en Importaciones | Comprobar que `queue` corre |
| Cola pendiente creciente | `ops:status` (`queue_pending`) | Más workers o revisar un trabajo atascado |

## 5. Respaldo y restauración de PostgreSQL
Responsabilidad de la VM de base de datos; mínimo recomendado:
- Respaldo lógico diario: `pg_dump --format=custom --file=jge_AAAAMMDD.dump <base>` guardado fuera de la VM, con retención de al menos 30 días.
- **Probar la restauración** al menos una vez por trimestre en una base vacía: `pg_restore --clean --if-exists --dbname=<base_de_prueba> jge_AAAAMMDD.dump`, y comprobar que la aplicación arranca contra ella.
- Respaldar también los discos privados con archivos del usuario: `storage/app/private` (importaciones, conversaciones y cotizaciones adjuntas). `php artisan conversations:verify-files --checksums` comprueba que los adjuntos de conversaciones existen y no cambiaron.
- La tabla `audit_logs` es de solo escritura para la aplicación; revocar `UPDATE` y `DELETE` al usuario de la aplicación en la VM (ver README).

## 6. Después de un despliegue
1. `php artisan migrate --force` y `php artisan db:seed --class=RolePermissionSeeder --force` (da de alta los permisos nuevos; es repetible y no devuelve permisos que administración quitó).
2. Reiniciar `queue` y `reverb` (cargan el código al arrancar).
3. `php artisan ops:status` en verde.
4. Revisar la CI del commit desplegado y el `CHANGELOG.md`.

## Empezar con información real: `data:reset`

Borra todo lo que nace de operar el sistema y conserva el maestro, las cuentas y la configuración. **Es irreversible**: haga antes una copia (sección 5: Respaldo y restauración de PostgreSQL).

| Se conserva | Se borra |
|---|---|
| Compañías, sucursales, vendedores, facturadores, productos (con sus unidades) | Ventas e importaciones (con sus errores), metas |
| Usuarios, roles, permisos y a qué compañías/sucursales llega cada cuenta | Clientes y sus interacciones, cotizaciones (y sus PDF), crédito, notas de crédito, promesas de pago |
| Configuración: correo, WhatsApp (credenciales cifradas, plantillas, respuestas rápidas), webhooks, avisos, automatizaciones, tipos de cliente | Inventario (saldos, movimientos, recepciones, faltantes) |
| | Análisis (RFM, alertas, ventas cruzadas), avisos, resúmenes, conversaciones de WhatsApp con sus adjuntos y consentimientos, historial de Auditoría |

```bash
docker compose exec backend php artisan data:reset --dry-run     # solo muestra qué se borraría y qué se conserva
docker compose exec backend php artisan data:reset               # pide escribir el nombre de la base para continuar
docker compose exec backend php artisan data:reset --keep-audit  # conserva también la Auditoría
docker compose exec backend php artisan data:reset --confirm=jge # sin preguntar (scripts); el valor debe ser el nombre de la base
```

- Se hace en una sola transacción: o queda todo limpio o no se toca nada. Los números de registro vuelven a empezar en 1.
- Quedan registradas en Auditoría la operación y cuántas filas se borraron (`system.data_reset`).
- Toda tabla nueva debe clasificarse en `App\Services\DataReset` (conservar o borrar); una prueba falla si falta alguna y el comando se niega a ejecutarse.
- Tras borrar, vuelva a cargar los clientes y las ventas reales desde Importaciones y ejecute `php artisan insights:refresh` si quiere el análisis al día.
