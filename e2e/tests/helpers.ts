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

/** La lista de opciones del desplegable: «Sucursales» también existe en Administración, así que se busca dentro de ella. */
export const reportsList = (page: Page) => menu(page).getByRole('list', { name: 'Reportes' })

/** Abre el menú desplegable «Reportes» (si está cerrado) y entra a una de sus opciones. */
export async function openReport(page: Page, option: 'Vendedores' | 'Operadores' | 'Sucursales' | 'Conciliación') {
  const group = menu(page).getByRole('button', { name: 'Reportes' })
  if ((await group.getAttribute('aria-expanded')) !== 'true') await group.click()
  await reportsList(page).getByRole('link', { name: option, exact: true }).click()
}
