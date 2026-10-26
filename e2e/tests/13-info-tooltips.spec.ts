import { expect, test } from '@playwright/test'
import { loginOk } from './helpers'

/** La «i» explica cada dato sin mover nada: el globo no cambia el tamaño de la página y siempre queda dentro de la ventana. */
for (const [width, height] of [[1366, 768], [375, 667]] as const) {
  test(`la «i» muestra la explicación y la fórmula sin desbordar la pantalla (${width}×${height})`, async ({ page }) => {
    await page.setViewportSize({ width, height })
    await loginOk(page, 'admin')
    await page.goto('/productos')
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible()
    await page.waitForTimeout(1000)

    const size = () => page.evaluate(() => ({ w: document.documentElement.scrollWidth, h: document.documentElement.scrollHeight }))
    const before = await size()

    for (const label of ['Productos en el catálogo', 'Venta con catálogo']) {
      const i = page.getByRole('button', { name: `Qué significa: ${label}` })
      await i.scrollIntoViewIfNeeded()
      await i.hover()
      const tip = page.getByRole('tooltip')
      await expect(tip).toBeVisible()
      const box = await tip.boundingBox()
      expect(box, label).not.toBeNull()
      expect(box!.x, label).toBeGreaterThanOrEqual(0)
      expect(box!.y, label).toBeGreaterThanOrEqual(0)
      expect(box!.x + box!.width, label).toBeLessThanOrEqual(width)
      expect(box!.y + box!.height, label).toBeLessThanOrEqual(height)
      if (label === 'Venta con catálogo') await expect(tip).toContainText('Fórmula:')
      expect(await size(), label).toEqual(before)
      await page.keyboard.press('Escape')
      await expect(tip).toHaveCount(0)
    }
  })
}
