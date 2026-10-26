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

/** Cada «i» de las pantallas con datos: abre dentro de la ventana y no cambia el ancho de la página. Un caso por pantalla y tamaño. */
const SCREENS: [string, number][] = [
  ['/', 1], ['/ventas', 8], ['/metas', 1], ['/seguimiento', 10], ['/cotizaciones', 1], ['/credito', 1], ['/cartera', 1],
  ['/reportes/vendedores', 5], ['/reportes/operadores', 5], ['/reportes/sucursales', 5], ['/reportes/productos', 3], ['/reportes/conciliacion', 3],
  ['/inteligencia', 0], ['/inteligencia/segmentos', 0], ['/inteligencia/concentracion', 0], ['/inteligencia/alertas', 0], ['/inteligencia/estacionalidad', 0], ['/inteligencia/venta-cruzada', 0],
  ['/inventario', 1], ['/inventario/rotacion', 1], ['/inventario/faltantes', 1], ['/inventario/movimientos', 1],
]
const MAX_PER_PAGE = 25

for (const [width, height] of [[1366, 768], [375, 667]] as const) {
  for (const [path, minimum] of SCREENS) {
    test(`las «i» de ${path} se abren dentro de la ventana (${width}×${height})`, async ({ page }) => {
      test.setTimeout(120_000)
      await page.setViewportSize({ width, height })
      await loginOk(page, 'admin')
      await page.goto(path)
      await expect(page.getByRole('heading', { level: 1 })).toBeVisible()
      await page.waitForTimeout(1500)
      const buttons = page.getByRole('button', { name: /^Qué significa/ })
      const count = await buttons.count()
      expect(count, path).toBeGreaterThanOrEqual(minimum)
      const size = () => page.evaluate(() => ({ w: document.documentElement.scrollWidth, h: document.documentElement.scrollHeight }))
      const before = await size()
      const step = Math.max(1, Math.ceil(count / MAX_PER_PAGE))
      for (let i = 0; i < count; i += step) {
        const b = buttons.nth(i)
        await b.scrollIntoViewIfNeeded()
        await b.hover()
        const tip = page.getByRole('tooltip')
        await expect(tip, `${path} #${i}`).toBeVisible()
        const box = await tip.boundingBox()
        expect(box!.x, `${path} #${i}`).toBeGreaterThanOrEqual(0)
        expect(box!.x + box!.width, `${path} #${i}`).toBeLessThanOrEqual(width)
        expect(box!.y, `${path} #${i}`).toBeGreaterThanOrEqual(0)
        expect(box!.y + box!.height, `${path} #${i}`).toBeLessThanOrEqual(height)
        await page.keyboard.press('Escape')
      }
      expect((await size()).w, `${path}: ancho`).toBe(before.w)
    })
  }
}
