import { expect, test } from '@playwright/test'
import { loginOk, menu } from './helpers'

test.describe('El menú muestra solo lo que el rol puede usar', () => {
  test('administración ve la sección de administración', async ({ page }) => {
    await loginOk(page, 'admin')

    await expect(menu(page).getByRole('link', { name: 'Usuarios' })).toBeVisible()
    await expect(menu(page).getByRole('link', { name: 'Ventas' })).toBeVisible()
  })

  test('un vendedor no ve administración ni importaciones', async ({ page }) => {
    await loginOk(page, 'vendedor1')

    await expect(menu(page).getByRole('link', { name: 'Usuarios' })).toHaveCount(0)
    await expect(menu(page).getByRole('link', { name: 'Importaciones' })).toHaveCount(0)
  })

  test('el servidor también lo impide: un vendedor que escribe la dirección de administración no ve la pantalla', async ({ page }) => {
    await loginOk(page, 'vendedor1')
    await page.goto('/admin/usuarios')

    await expect(page.getByRole('table', { name: 'Usuarios' })).toHaveCount(0)
  })
})
