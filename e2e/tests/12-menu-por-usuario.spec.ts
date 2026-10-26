import { expect, test, type Browser, type Page } from '@playwright/test'
import { loginOk, menu } from './helpers'

/**
 * Cambia lo que ve una cuenta (y lo restablece al terminar): solo con E2E_ALLOW_WRITES=1, en una base de pruebas.
 * Verifica de punta a punta que lo que el administrador oculta desaparece del menú, no se abre escribiendo la dirección y el servidor
 * rechaza sus datos.
 */
test.skip(process.env.E2E_ALLOW_WRITES !== '1', 'Cambia el menú de una cuenta: use E2E_ALLOW_WRITES=1 en una base de pruebas.')

const GERENTE = 'Gerente Demo (@gerente) · Gerente General'
const API = process.env.E2E_API_URL ?? 'http://localhost:8000/api/v1'

async function chooseGerente(page: Page) {
  await page.goto('/admin/menu-usuarios')
  await expect(page.getByRole('heading', { name: 'Menú por usuario' })).toBeVisible()
  await page.getByLabel('Persona').selectOption({ label: GERENTE })
}

async function asGerente<T>(browser: Browser, work: (page: Page) => Promise<T>): Promise<T> {
  const context = await browser.newContext({ baseURL: process.env.E2E_BASE_URL ?? 'http://localhost:5173', locale: 'es' })
  try {
    const page = await context.newPage()
    await loginOk(page, 'gerente')
    return await work(page)
  } finally {
    await context.close()
  }
}

/** Código y estado con que el servidor responde a una consulta de la propia sesión de la página. */
const ask = (page: Page, path: string) =>
  page.evaluate(async ([url]) => {
    const r = await fetch(url!, { credentials: 'include', headers: { Accept: 'application/json' } })
    return { status: r.status, code: ((await r.json().catch(() => ({}))) as { code?: string }).code ?? null }
  }, [`${API}${path}`])

const item = (page: Page, name: string) => page.getByRole('checkbox', { name: `Reportes: ${name}` })

async function save(page: Page) {
  await page.getByRole('button', { name: 'Guardar cambios' }).click()
  await expect(page.getByText(/Menú de .* actualizado/)).toBeVisible()
}

/**
 * En una instalación nueva el rol Gerente solo trae los reportes (los demás permisos se conceden desde «Roles y permisos»), así que la
 * prueba oculta reportes: primero uno, luego el grupo entero. Lo compartido con otras pantallas lo cubren las pruebas del servidor.
 */
test.setTimeout(90_000)

test('lo que el administrador oculta desaparece del menú, no se abre por la dirección y el servidor lo rechaza', async ({ page, browser }) => {
  await loginOk(page, 'admin')
  await chooseGerente(page)

  try {
    // 1. Un solo reporte oculto: los demás siguen.
    await page.getByRole('checkbox', { name: 'Reportes: Mezcla de productos' }).uncheck()
    await save(page)
    await asGerente(browser, async (g) => {
      await menu(g).getByRole('button', { name: 'Reportes' }).click()
      await expect(menu(g).getByRole('list', { name: 'Reportes' }).getByRole('link', { name: 'Vendedores', exact: true })).toBeVisible()
      await expect(menu(g).getByRole('list', { name: 'Reportes' }).getByRole('link', { name: 'Productos', exact: true })).toHaveCount(0)
      await g.goto('/reportes/productos')
      await expect(g).toHaveURL(/\/$/)
      expect(await ask(g, '/reports/products?from=2026-01-01&to=2026-10-31')).toEqual({ status: 403, code: 'menu_option_hidden' })
      expect((await ask(g, '/reports/sellers?from=2026-01-01&to=2026-10-31')).code).not.toBe('menu_option_hidden')
      expect((await ask(g, '/reports/context')).code).not.toBe('menu_option_hidden')   // los demás reportes usan sus filtros
    })

    // 2. Todo el grupo oculto: el grupo desaparece del menú y sus datos se bloquean.
    for (const name of ['Reporte de vendedores', 'Reporte de operadores', 'Reporte de sucursales', 'Conciliación de ventas']) await item(page, name).uncheck()
    await expect(page.getByText(/dejará de ver el grupo Reportes/)).toBeVisible()
    await save(page)
    await asGerente(browser, async (g) => {
      await expect(menu(g).getByRole('link', { name: 'Inicio' })).toBeVisible()
      await expect(menu(g).getByRole('button', { name: 'Reportes' })).toHaveCount(0)
      await g.goto('/reportes/vendedores')
      await expect(g).toHaveURL(/\/$/)
      await g.goto('/admin/menu-usuarios')
      await expect(g).toHaveURL(/\/$/)
      expect(await ask(g, '/reports/context')).toEqual({ status: 403, code: 'menu_option_hidden' })
      expect((await ask(g, '/admin/menu-access')).status).toBe(403)
    })
  } finally {
    // Dejar la cuenta como estaba: ve todo lo que su rol permite.
    await page.goto('/admin/menu-usuarios')
    await expect(page.getByRole('heading', { name: 'Menú por usuario' })).toBeVisible()
    await page.getByLabel('Persona').selectOption({ label: GERENTE })
    const showAll = page.getByRole('button', { name: 'Mostrar todo' })
    if (await showAll.isEnabled()) {
      await showAll.click()
      await save(page)
    }
  }

  await asGerente(browser, async (g) => {
    await expect(menu(g).getByRole('button', { name: 'Reportes' })).toBeVisible()
  })
})
