import { expect, test } from '@playwright/test'
import { loginOk, menu } from './helpers'

test.describe('Reporte de vendedores', () => {
  test('administración evalúa a los vendedores y abre el detalle de facturación', async ({ page }) => {
    await loginOk(page, 'admin')
    await menu(page).getByRole('link', { name: 'Reporte de vendedores' }).click()

    await expect(page.getByRole('heading', { name: 'Reporte de vendedores' })).toBeVisible()
    await page.getByLabel('Periodo').selectOption('ytd')
    const table = page.getByRole('table', { name: 'Evaluación de vendedores' })
    await expect(table).toBeVisible()
    await expect(page.getByRole('alert')).toHaveCount(0)

    // El contraste entre la venta consolidada y la suma de los vendedores se informa siempre.
    await expect(page.getByText(/coincide con la suma de los vendedores|no coincide con la suma de los vendedores/)).toBeVisible()

    const first = table.getByRole('button', { name: /Ver la facturación de/ }).first()
    await first.click()
    await expect(page.getByRole('dialog')).toBeVisible()
    await expect(page.getByRole('table', { name: /Documentos facturados por/ })).toBeVisible()
  })

  test('un vendedor no ve el reporte ni puede abrirlo escribiendo la dirección', async ({ page }) => {
    await loginOk(page, 'vendedor1')
    await expect(menu(page).getByRole('link', { name: 'Reporte de vendedores' })).toHaveCount(0)

    await page.goto('/reportes/vendedores')

    await expect(page.getByRole('table', { name: 'Evaluación de vendedores' })).toHaveCount(0)
  })

  test('gerencia y supervisión sí lo ven', async ({ page }) => {
    for (const who of ['gerente', 'supervisor'] as const) {
      await loginOk(page, who)
      await expect(menu(page).getByRole('link', { name: 'Reporte de vendedores' })).toBeVisible()
      await page.getByRole('button', { name: 'Cerrar sesión' }).click()
      await expect(page).toHaveURL(/\/login/)
    }
  })
})
