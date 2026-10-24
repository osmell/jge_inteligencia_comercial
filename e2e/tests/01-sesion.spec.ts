import { expect, test } from '@playwright/test'
import { login, loginOk } from './helpers'

test.describe('Inicio y cierre de sesión', () => {
  test('una contraseña incorrecta no entra y avisa', async ({ page }) => {
    await login(page, 'admin', 'contraseña-equivocada')

    await expect(page).toHaveURL(/\/login/)
    await expect(page.getByRole('alert')).toBeVisible()
    await expect(page.getByRole('button', { name: 'Cerrar sesión' })).toHaveCount(0)
  })

  test('con credenciales correctas entra, conserva la sesión al recargar y cierra', async ({ page }) => {
    await loginOk(page, 'admin')
    await expect(page).not.toHaveURL(/\/login/)

    await page.reload()
    await expect(page.getByRole('button', { name: 'Cerrar sesión' })).toBeVisible()

    await page.getByRole('button', { name: 'Cerrar sesión' }).click()
    await expect(page).toHaveURL(/\/login/)
    await page.goto('/ventas')
    await expect(page).toHaveURL(/\/login/)     // sin sesión, una pantalla protegida manda a iniciar sesión
  })
})

test('cerrar sesión en un equipo cierra también los demás de esa persona', async ({ browser }) => {
  const laptop = await (await browser.newContext()).newPage()
  const phone = await (await browser.newContext()).newPage()
  await loginOk(laptop, 'supervisor')
  await loginOk(phone, 'supervisor')

  await laptop.getByRole('button', { name: 'Cerrar sesión' }).click()
  await expect(laptop).toHaveURL(/\/login/)

  // El otro equipo seguía con una sesión abierta: ya no vale.
  await phone.goto('/ventas')
  await expect(phone).toHaveURL(/\/login/)
})
