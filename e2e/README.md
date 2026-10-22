# Pruebas de extremo a extremo (Playwright)

Verifican en un **navegador real** los flujos que no pueden romperse: iniciar y cerrar sesión, la barra de comandos, el menú por rol
(y que el servidor también lo impide), el tablero de ventas, la administración, la ficha de clientes y la creación de una cotización.
Las pruebas unitarias y de pantalla (Vitest y PHPUnit) ya cubren la lógica; estas cubren que **todo junto** funcione.

## Requisitos
La aplicación en marcha con los usuarios ficticios de desarrollo (contraseña `Desarrollo-123`):
```bash
docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d
docker compose exec backend php artisan migrate --seed          # usuarios de desarrollo
docker compose exec -u $(id -u) backend php artisan db:seed --class=E2eSeeder           # el cliente fijo «Cliente E2E»
docker compose exec -u $(id -u) backend php artisan db:seed --class=DemoSalesSeeder   # opcional: datos de ventas
```

## Ejecutar
Con el navegador de Playwright en un contenedor (no instala nada en su equipo):
```bash
docker run --rm --network host -v "$PWD/e2e:/e2e" -v jge_e2e_modules:/e2e/node_modules -w /e2e \
  mcr.microsoft.com/playwright:v1.64.0-noble sh -c "npm ci && npx playwright test"
```
O, con Node instalado: `cd e2e && npm ci && npx playwright install chromium && npx playwright test`.

- `E2E_BASE_URL` cambia la dirección del frontend (por omisión `http://localhost:5173`; la API debe estar en `:8000`).
- Las pruebas **solo leen** salvo `tests/07-cotizacion.spec.ts`, que crea una cotización y exige `E2E_ALLOW_WRITES=1`. No lo use en una base con datos reales.
- Las pruebas de clientes y cotizaciones usan el cliente fijo «Cliente E2E» (`E2eSeeder`).
- Para no tocar la base de desarrollo, levante una instalación aparte (ver `.github/workflows/e2e.yml`, que hace exactamente eso con SQLite).
- En fallos quedan capturas y un *trace* en `test-results/` (`npx playwright show-trace <archivo>`).

## Integración continua
`.github/workflows/e2e.yml` levanta la aplicación (SQLite, datos de demostración) y ejecuta todas, incluida la que escribe. Corre cada noche y a
demanda (verificado en GitHub: 11 de 11 en menos de 25 s) (*Actions → E2E → Run workflow*); no bloquea los Pull Requests porque es más lenta que la CI normal.

## Agregar un flujo
Un archivo `NN-nombre.spec.ts` por flujo, con `loginOk(page, '<usuario>')` y selectores por **rol y nombre accesible** (no por clases CSS): si una prueba
no encuentra algo así, probablemente a una persona con lector de pantalla también le falta.
