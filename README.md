# JGE Inteligencia Comercial

Plataforma web de inteligencia comercial, seguimiento de clientes, crédito e inventario para JGE Soluciones Integrales.
Contexto y fases del proyecto: carpeta [`docs/`](docs/README.md). **Estado: Fase 1 (fundación y seguridad).**

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
- El servicio `queue` ejecuta `php artisan queue:work`; es necesario para validar e importar archivos. Tras cambiar código de trabajos, reinícialo: `docker compose restart queue`.
- `backend/.env` se lee desde el volumen montado; **no** añadir `env_file` al Compose (las variables del contenedor tendrían prioridad sobre `phpunit.xml`).
- Para usar la VM de PostgreSQL: levantar solo `docker-compose.yml` y poner `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` de la VM en `backend/.env`.

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

**Un permiso por opción del menú**, concedible por rol desde «Roles y permisos». El rol Administrador siempre tiene todos y no es editable; `roles.manage` no se puede conceder a otros roles. Quien tenga `users.manage` sin ser Administrador solo ve y administra usuarios de las compañías en las que tiene acceso completo, y no puede asignar el rol Administrador. `audit.view` limita los registros a sus compañías.

No hay `DELETE`: las entidades se desactivan con `is_active`.
