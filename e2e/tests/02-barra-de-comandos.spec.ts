import { expect, test } from '@playwright/test'
import { loginOk } from './helpers'

test('la barra de comandos (tecla /) lleva a una pantalla permitida', async ({ page }) => {
  await loginOk(page, 'admin')

  await page.keyboard.press('/')
  const bar = page.getByRole('combobox', { name: /Ir a una pantalla/ })
  await expect(bar).toBeFocused()

  await bar.fill('cotiz')
  await expect(page.getByRole('option', { name: /Cotizaciones/ })).toBeVisible()
  await bar.press('Enter')

  await expect(page).toHaveURL(/\/cotizaciones$/)
  await expect(page.getByRole('heading', { name: 'Cotizaciones' })).toBeVisible()
})

test('escribir en un campo no roba la tecla /', async ({ page }) => {
  await loginOk(page, 'admin')
  await page.goto('/cotizaciones')
  const search = page.getByLabel('Buscar por número, referencia o cliente')

  await search.focus()
  await page.keyboard.type('a/b')

  await expect(search).toHaveValue('a/b')
})
