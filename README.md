# JGE Inteligencia Comercial

Plataforma web de inteligencia comercial, seguimiento de clientes, crédito e inventario para JGE Soluciones Integrales.
Contexto y fases del proyecto: carpeta [`docs/`](docs/README.md). **Estado: Fases 1 a 12 implementadas (ver `docs/`); en revisión funcional ajuste por ajuste (`docs/23_AJUSTES_REVISION.md`).** Para colaborar: [`CONTRIBUTING.md`](CONTRIBUTING.md) y la guía de desarrollo [`docs/24`](docs/24_ANALISIS_CODIGO_Y_GUIA_DESARROLLO.md).

## Estructura

```
backend/     Laravel 13 (PHP 8.5) — API REST /api/v1
frontend/    React 19 + TypeScript + Vite + Tailwind 4
docker-compose.yml       backend, queue (worker), frontend y Redis (sin PostgreSQL)
docker-compose.dev.yml   PostgreSQL local SOLO para desarrollo
```

PostgreSQL de producción vive en una **VM dedicada**; no se despliega con la aplicación.

## Arranque en desarrollo

```bash
cp backend/.env.example backend/.env        # ajustar DB_PASSWORD si hace falta
docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d --build
docker compose exec backend php artisan key:generate
docker compose exec backend php artisan migrate --seed
```

- Frontend: http://localhost:5173 — API: http://localhost:8000/api/v1
- El servicio `queue` ejecuta `php artisan queue:work` con 1 GB de memoria; es necesario para validar e importar archivos (hasta 200 000 filas). Tras cambiar código de trabajos, reinícialo: `docker compose restart queue`. Si un archivo se queda en «En cola», revisa que esté corriendo: `docker compose ps queue` (si salió, `docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d queue`).
- `backend/.env` se lee desde el volumen montado; **no** añadir `env_file` al Compose (las variables del contenedor tendrían prioridad sobre `phpunit.xml`).
- Para usar la VM de PostgreSQL: levantar solo `docker-compose.yml` y poner `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` de la VM en `backend/.env`.

### Ventas de demostración

```bash
docker compose exec -u $(id -u) backend php artisan db:seed --class=DemoSalesSeeder
```

Genera 12 meses de ventas **ficticias** (≈ 6 000 documentos y 17 000 líneas; 2 compañías, 3 sucursales, 8 vendedores, 5 operadores, 150 clientes y 80 productos) con tendencia, estacionalidad y vendedores de rendimiento distinto, para probar los reportes. Es reproducible, repetible (borra y regenera solo la serie `DEMO`), no se ejecuta en producción y vincula a `vendedor1` y `vendedor2` con sus vendedores. Aparece en Importaciones como «DATOS DE DEMOSTRACIÓN».

### Usuarios ficticios de desarrollo

Contraseña de todos: `Desarrollo-123` (solo entornos no productivos).

| Usuario | Rol | Ámbito |
|---|---|---|
| `admin` | Administrador | Todo |
| `gerente` | Gerente General | Alfa y Beta |
| `supervisor` | Supervisor de Ventas | Alfa (Central y Norte) |
| `vendedor1` | Vendedor | Alfa (Central) |
| `vendedor2` | Vendedor | Beta (Central) |

## Pruebas y chequeos

La integración continua (`.github/workflows/ci.yml`) ejecuta los mismos chequeos en cada Pull Request y en `main`.

```bash
# Backend: estilo (Pint), análisis estático (Larastan) y pruebas
docker compose exec -u $(id -u) backend composer check

# Frontend: tipos, lint (oxlint) y pruebas (en el contenedor como root: node_modules es un volumen)
docker compose exec frontend sh -c "npm run typecheck && npm run lint && npm test"
```

Las pruebas del backend usan SQLite en memoria (forzado en `phpunit.xml`); nunca tocan la base de desarrollo.

Al ejecutar `artisan` que cree archivos, usar `docker compose exec -u $(id -u) backend …` para que no queden como root.

## Seguridad: puntos de despliegue

- Acceso por **nombre de usuario** único (no correo). Sesión por cookie (Sanctum SPA) con CSRF; frontend y API deben compartir sitio. Ajustar `FRONTEND_URL`, `SANCTUM_STATEFUL_DOMAINS`, `SESSION_DOMAIN` y `SESSION_SECURE_COOKIE=true` en producción.
- La autorización se aplica en el backend (middleware `permission:` y ámbito de compañía/sucursal en los modelos); el menú del frontend solo oculta controles.
- `audit_logs` es de solo escritura en la aplicación. **En la VM de PostgreSQL**, revocar `UPDATE` y `DELETE` sobre `audit_logs` al usuario de la aplicación para blindarlo también a nivel de base de datos.
- Los datos ficticios (`DevDataSeeder`) no se cargan en producción. `APP_DEBUG=false` en producción.

## API de Fase 1

| Método y ruta | Acceso |
|---|---|
| `POST /auth/login`, `POST /auth/logout`, `GET /auth/me` | Login público; resto autenticado |
| `GET /companies`, `GET /branches` | Autenticado, limitado a su ámbito |
| `/admin/companies` (listar, ver, crear, editar) | `companies.manage` (solo el Administrador crea) |
| `/admin/branches` (listar, ver, crear, editar) | `branches.manage` |
| `/admin/users`, `GET /admin/roles` (listar, ver, crear, editar) | `users.manage` |
| `GET/PUT /admin/role-permissions` | `roles.manage` (solo Administrador) |
| `GET /admin/audit-logs` | `audit.view` |
| `/admin/imports` (subir, listar, ver, errores, errores.csv, confirmar, descartar), `/admin/imports/types`, `/admin/imports/templates/{tipo}` | `imports.manage` (quien no es Administrador ve solo sus lotes) |

**Un permiso por opción del menú**, concedible por rol desde «Roles y permisos». El rol Administrador siempre tiene todos y no es editable; `roles.manage` no se puede conceder a otros roles. Quien tenga `users.manage` sin ser Administrador solo ve y administra usuarios de las compañías en las que tiene acceso completo, y no puede asignar el rol Administrador. `audit.view` limita los registros a sus compañías.

**Importaciones:** `POST /admin/imports` (multipart: `type`, `file` .xlsx) guarda el archivo en el disco privado y lo valida en cola **sin escribir ventas**; se consulta el lote hasta que quede `validated` o `rejected`; `POST …/confirm` (202) importa en cola solo si está validado (una segunda confirmación responde 409) y `POST …/discard` lo descarta. El CSV de errores neutraliza fórmulas. Los nombres provisionales de columnas, formatos de fecha y la tasa de IVA se ajustan en `backend/config/imports.php`.

No hay `DELETE`: las entidades se desactivan con `is_active`.
