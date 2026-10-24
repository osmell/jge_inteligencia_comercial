import { expect, test } from '@playwright/test'
import { loginOk } from './helpers'

/**
 * La interfaz debe funcionar con una política de seguridad de contenido (CSP) estricta: sin scripts ni estilos externos ni `eval`.
 * Esta prueba recorre las pantallas principales y falla si el navegador bloquea algo. Es más útil cuando la interfaz se sirve CON la cabecera
 * (ver docs/28_SEGURIDAD.md); sin ella pasa, porque no hay nada que bloquear.
 */
test('recorrer las pantallas principales no produce ninguna violación de la política de contenido', async ({ page }) => {
  const violations: string[] = []
  page.on('console', (m) => {
    if (/content security policy|refused to (load|apply|execute|connect|frame)/i.test(m.text())) violations.push(m.text())
  })
  page.on('pageerror', (e) => violations.push(`error de página: ${e.message}`))

  await loginOk(page, 'admin')
  for (const path of ['/', '/ventas', '/metas', '/clientes', '/cotizaciones', '/credito', '/inteligencia', '/inventario', '/inventario/unidades', '/conversaciones', '/admin/usuarios', '/importaciones', '/reportes/vendedores', '/reportes/operadores', '/reportes/sucursales', '/reportes/conciliacion']) {
    await page.goto(path)
    await expect(page.getByRole('heading', { level: 1 }).first()).toBeVisible()
  }

  expect(violations, violations.join('\n')).toEqual([])
})
