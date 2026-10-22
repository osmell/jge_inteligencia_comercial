import { expect, test } from '@playwright/test'
import { PASSWORD, login, menu } from './helpers'

/**
 * Crea/cambia datos: solo con E2E_ALLOW_WRITES=1. Usa la cuenta `primer.ingreso`, que `E2eSeeder` deja con clave temporal cada vez que se
 * siembra (volver a ejecutar `php artisan db:seed --class=E2eSeeder` la deja lista para repetir la prueba).
 */
test.skip(process.env.E2E_ALLOW_WRITES !== '1', 'Cambia la clave de una cuenta: use E2E_ALLOW_WRITES=1 en una base de pruebas.')

const NEW = 'Definitiva-67890'

test('una cuenta con clave temporal debe crear la suya antes de usar el sistema', async ({ page }) => {
  await login(page, 'primer.ingreso' as never)

  // Obligada a cambiarla: sin menú y sin poder ir a otra pantalla.
  await expect(page).toHaveURL(/\/cambiar-clave/)
  await expect(page.getByRole('heading', { name: 'Crea tu clave' })).toBeVisible()
  await expect(menu(page)).toHaveCount(0)
  await page.goto('/clientes')
  await expect(page).toHaveURL(/\/cambiar-clave/)

  // Errores de validación junto al campo, sin avanzar.
  await page.getByLabel('Clave temporal').fill('Clave-equivocada-1')
  await page.getByLabel('Clave nueva', { exact: true }).fill(NEW)
  await page.getByLabel('Repite la clave nueva').fill(NEW)
  await page.getByRole('button', { name: 'Guardar clave' }).click()
  await expect(page.getByText('La clave actual no es correcta.')).toBeVisible()

  await page.getByLabel('Clave temporal').fill(PASSWORD)
  await page.getByLabel('Clave nueva', { exact: true }).fill('corta1')
  await page.getByLabel('Repite la clave nueva').fill('corta1')
  await page.getByRole('button', { name: 'Guardar clave' }).click()
  await expect(page.getByRole('heading', { name: 'Crea tu clave' })).toBeVisible()

  // Cambio correcto: entra al sistema sin volver a iniciar sesión.
  await page.getByLabel('Clave nueva', { exact: true }).fill(NEW)
  await page.getByLabel('Repite la clave nueva').fill(NEW)
  await page.getByRole('button', { name: 'Guardar clave' }).click()
  await expect(page).toHaveURL(/\/$/)
  await expect(menu(page)).toBeVisible()

  // La clave temporal ya no sirve y la nueva sí.
  await page.getByRole('button', { name: 'Cerrar sesión' }).click()
  await login(page, 'primer.ingreso' as never, PASSWORD)
  await expect(page.getByRole('alert')).toBeVisible()
  await login(page, 'primer.ingreso' as never, NEW)
  await expect(menu(page)).toBeVisible()
  await expect(page).not.toHaveURL(/cambiar-clave/)
})
