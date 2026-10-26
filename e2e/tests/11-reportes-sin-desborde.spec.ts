import { expect, test } from '@playwright/test'
import { loginOk } from './helpers'

/** La página nunca debe desplazarse sola: las tablas anchas se desplazan dentro de su tarjeta (el texto `sr-only` de los semáforos
 *  llegó a ensanchar y alargar todo el documento porque escapaba del recorte de la tabla). */
for (const [width, height] of [[1366, 768], [1920, 1080]] as const) {
  test(`los reportes no desbordan la pantalla (${width}×${height})`, async ({ page }) => {
    await page.setViewportSize({ width, height })
    await loginOk(page, 'admin')
    for (const report of ['vendedores', 'operadores', 'sucursales', 'productos', 'conciliacion']) {
      await page.goto(`/reportes/${report}`)
      await expect(page.getByRole('heading', { level: 1 })).toBeVisible()
      await page.waitForTimeout(1500)
      const size = await page.evaluate(() => ({ w: document.documentElement.scrollWidth, h: document.documentElement.scrollHeight }))
      expect(size, report).toEqual({ w: width, h: height })
    }
  })
}
