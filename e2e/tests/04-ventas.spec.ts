import { expect, test } from '@playwright/test'
import { loginOk } from './helpers'

test('el tablero de ventas carga con sus filtros y cambia de dimensión', async ({ page }) => {
  await loginOk(page, 'admin')
  await page.goto('/ventas')

  await expect(page.getByRole('heading', { name: 'Ventas', exact: true })).toBeVisible()
  await expect(page.getByLabel('Periodo')).toBeVisible()
  await expect(page.getByLabel('Comparar con')).toBeVisible()
  await expect(page.getByRole('alert')).toHaveCount(0)

  await page.getByLabel('Periodo').selectOption('custom')
  await expect(page.getByLabel('Desde')).toBeVisible()
  await expect(page.getByLabel('Hasta')).toBeVisible()
})
