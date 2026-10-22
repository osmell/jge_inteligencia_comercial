import { expect, test } from '@playwright/test'
import { loginOk } from './helpers'

/** Crea datos: solo corre con E2E_ALLOW_WRITES=1 (por ejemplo en una base de pruebas, nunca en una de uso real). */
test.skip(process.env.E2E_ALLOW_WRITES !== '1', 'Crea registros: use E2E_ALLOW_WRITES=1 en una base de pruebas.')

test('se crea una cotización para un cliente y se cancela', async ({ page }) => {
  await loginOk(page, 'admin')
  await page.goto('/cotizaciones?nueva=1')

  const dialog = page.getByRole('dialog', { name: 'Nueva cotización' })
  await dialog.getByLabel('Buscar cliente').fill('Cliente E2E')
  await dialog.getByLabel('Cliente', { exact: true }).selectOption({ label: 'Cliente E2E (E2E-001)' })
  await dialog.getByLabel('Importe', { exact: true }).fill('150.00')
  await dialog.getByLabel('Descripción (qué se cotiza)').fill('Prueba E2E')
  await dialog.getByRole('button', { name: 'Crear cotización' }).click()

  await expect(page).toHaveURL(/\/cotizaciones\/\d+$/)
  await expect(page.getByText('Prueba E2E')).toBeVisible()

  await page.getByRole('button', { name: 'Cancelar', exact: true }).click()
  await page.getByRole('button', { name: 'Cancelar cotización' }).click()
  await expect(page.getByText('Cancelada').first()).toBeVisible()
})
