import { expect, test } from '@playwright/test'
import { loginOk } from './helpers'

/** Ancho de las ventanas emergentes de los reportes en escritorio: la de mezcla de productos es la más ancha (hasta 72 rem) para leer las
 *  descripciones; las de facturación y detalle miden 42 rem; ninguna pasa del ancho de la pantalla. */
const REM = 16
const REPORTS = [
  { path: 'productos', opener: /Ver el detalle de/, min: 70 * REM, max: 72 * REM },
  { path: 'vendedores', opener: /Ver la facturación de/, min: 41 * REM, max: 42 * REM },
  { path: 'operadores', opener: /Ver el detalle de/, min: 41 * REM, max: 42 * REM },
  { path: 'sucursales', opener: /Ver la facturación de/, min: 41 * REM, max: 42 * REM },
] as const

for (const [width, height] of [[1920, 1080], [1366, 768]] as const) {
  test(`las ventanas de los reportes conservan su ancho (${width}×${height})`, async ({ page }) => {
    await page.setViewportSize({ width, height })
    await loginOk(page, 'admin')

    for (const r of REPORTS) {
      await page.goto(`/reportes/${r.path}`)
      await expect(page.getByRole('heading', { level: 1 })).toBeVisible()
      await page.getByRole('button', { name: r.opener }).first().click()
      const dialog = page.getByRole('dialog')
      await expect(dialog).toBeVisible()
      const box = await dialog.boundingBox()
      expect(box, r.path).not.toBeNull()
      expect(box!.width, `${r.path} ancho de la ventana`).toBeGreaterThanOrEqual(Math.min(r.min, width - 32) - 1)
      expect(box!.width, `${r.path} ancho de la ventana`).toBeLessThanOrEqual(Math.min(r.max, width) + 1)
      await page.keyboard.press('Escape')
    }
  })
}
