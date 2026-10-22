import { expect, type Page } from '@playwright/test'

export const PASSWORD = process.env.E2E_PASSWORD ?? 'Desarrollo-123'

/** Usuarios ficticios de desarrollo (DevDataSeeder). */
export type Dev = 'admin' | 'gerente' | 'supervisor' | 'vendedor1' | 'vendedor2'

export async function login(page: Page, username: Dev, password = PASSWORD) {
  await page.goto('/login')
  await page.getByLabel('Usuario').fill(username)
  await page.getByLabel('Contraseña', { exact: true }).fill(password)
  await page.getByRole('button', { name: 'Ingresar' }).click()
}

export async function loginOk(page: Page, username: Dev) {
  await login(page, username)
  await expect(page.getByRole('button', { name: 'Cerrar sesión' })).toBeVisible()
  await expect(page).toHaveURL(/\/$/)                       // el inicio termina de cargar antes de seguir a otra pantalla
  await expect(page.getByRole('heading', { level: 1 })).toBeVisible()
}

export const menu = (page: Page) => page.getByRole('navigation', { name: 'Principal' })
