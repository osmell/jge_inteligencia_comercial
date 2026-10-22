import { expect, test } from '@playwright/test'
import { loginOk } from './helpers'

test('se busca un cliente, se abre su ficha y se llega al reporte de ventas por cliente', async ({ page }) => {
  await loginOk(page, 'admin')
  await page.goto('/clientes')

  await page.getByLabel(/Buscar/).first().fill('Cliente E2E')
  const link = page.getByRole('table').getByRole('link', { name: 'Cliente E2E' })
  await expect(link).toBeVisible()
  await link.click()

  await expect(page).toHaveURL(/\/clientes\/ficha/)
  await expect(page.getByRole('heading', { name: /Cliente E2E/ }).first()).toBeVisible()

  await page.goto('/clientes')
  await page.getByRole('link', { name: 'Ventas por cliente' }).click()
  await expect(page).toHaveURL(/\/clientes\/reporte/)
})
