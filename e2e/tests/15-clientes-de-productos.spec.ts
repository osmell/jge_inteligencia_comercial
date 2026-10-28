import { expect, test } from '@playwright/test'
import { loginOk, openReport } from './helpers'

/** Reportes - Productos: la cifra «Clientes» abre la lista de quiénes son (solo lectura). */
test.describe('Clientes detrás de la mezcla de productos', () => {
  test('el número de Clientes abre la lista en una ventana y Escape la cierra', async ({ page }) => {
    await page.setViewportSize({ width: 1366, height: 768 })
    await loginOk(page, 'admin')
    await openReport(page, 'Productos')
    await expect(page.getByRole('heading', { name: 'Mezcla de productos' })).toBeVisible()
    await page.getByLabel('Periodo').selectOption('ytd')

    const ranking = page.getByRole('table', { name: /Lo más facturado por/ })
    const opener = ranking.getByRole('button', { name: /Ver los .* clientes de/ }).first()
    await expect(opener).toBeVisible()
    const total = Number((await opener.textContent())!.replace(/,/g, ''))

    await opener.click()
    const dialog = page.getByRole('dialog', { name: /^Clientes de/ })
    await expect(dialog).toBeVisible()
    await expect(dialog.getByText(new RegExp(`${total.toLocaleString('en-US')} clientes`))).toBeVisible()
    await expect(dialog.getByRole('table')).toBeVisible()
    await expect(dialog.getByRole('alert')).toHaveCount(0)

    const box = await dialog.boundingBox()
    expect(box!.width).toBeLessThanOrEqual(1366)
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
    await dialog.screenshot({ path: 'test-results/clientes-modal.png' })

    // Con más de 50 clientes pagina; el paginador muestra el total que dice el número.
    if (total > 50) {
      await expect(dialog.getByText(new RegExp(`1–50 de ${total.toLocaleString('en-US')}`))).toBeVisible()
      await dialog.getByRole('button', { name: 'Siguiente' }).click()
      await expect(dialog.getByText(/51–/)).toBeVisible()
    }

    await page.keyboard.press('Escape')
    await expect(dialog).toHaveCount(0)
  })

  test('en el detalle de una persona abre los clientes encima y Escape cierra solo la de arriba', async ({ page }) => {
    await loginOk(page, 'admin')
    await openReport(page, 'Productos')
    await expect(page.getByRole('heading', { name: 'Mezcla de productos' })).toBeVisible()
    await page.getByLabel('Periodo').selectOption('ytd')

    await page.getByRole('button', { name: /Ver el detalle de/ }).first().click()
    const detail = page.getByRole('dialog', { name: /^Mezcla de productos de/ })
    await expect(detail).toBeVisible()
    await detail.getByRole('button', { name: /Ver los .* clientes de/ }).first().click()
    const customers = page.getByRole('dialog', { name: /^Clientes de/ })
    await expect(customers).toBeVisible()
    await expect(customers.getByRole('table')).toBeVisible()
    await customers.screenshot({ path: 'test-results/clientes-modal-persona.png' })

    await page.keyboard.press('Escape')
    await expect(customers).toHaveCount(0)
    await expect(detail).toBeVisible()
  })
})
