import { expect, test } from '@playwright/test'
import { loginOk, menu, openGroup } from './helpers'

test.describe('El menú muestra solo lo que el rol puede usar', () => {
  test('administración ve la sección de administración', async ({ page }) => {
    await loginOk(page, 'admin')

    await expect(menu(page).getByRole('link', { name: 'Usuarios' })).toHaveCount(0)   // el grupo empieza cerrado
    await expect(await openGroup(page, 'Administración')).toBeVisible()
    await expect(menu(page).getByRole('link', { name: 'Usuarios' })).toBeVisible()
    await expect(menu(page).getByRole('link', { name: 'Menú por usuario' })).toBeVisible()
    await openGroup(page, 'Inteligencia')
    await expect(menu(page).getByRole('link', { name: 'Ventas' })).toBeVisible()
  })

  test('los grupos son desplegables, en el orden acordado, y solo General empieza abierto', async ({ page }) => {
    await loginOk(page, 'admin')

    const groups = menu(page).getByRole('button', { expanded: false })
    await expect(menu(page).getByRole('button', { name: 'General', exact: true })).toHaveAttribute('aria-expanded', 'true')
    await expect(menu(page).getByRole('link', { name: 'Inicio' })).toBeVisible()
    await expect(groups).toHaveText(['Administración', 'Catálogos', 'Inteligencia', 'Reportes', 'Datos'])

    const lists: Record<string, string[]> = {
      Administración: ['Usuarios', 'Menú por usuario', 'Roles y permisos', 'Auditoría', 'Automatizaciones', 'Correo', 'WhatsApp', 'Webhook'],
      Catálogos: ['Compañías', 'Sucursales', 'Clientes', 'Productos', 'Unidades'],
      Inteligencia: ['Ventas', 'Metas', 'Seguimiento', 'Cotizaciones', 'Crédito', 'Resúmenes', 'Inteligencia', 'Existencias', 'Rotación', 'Movimientos'],
      Reportes: ['Vendedores', 'Operadores', 'Sucursales', 'Productos', 'Conciliación'],
      Datos: ['Importaciones'],
    }
    for (const [group, names] of Object.entries(lists)) {
      await expect((await openGroup(page, group)).getByRole('link')).toHaveText(names)
    }
  })

  test('un vendedor no ve administración ni importaciones', async ({ page }) => {
    await loginOk(page, 'vendedor1')

    await expect(menu(page).getByRole('button', { name: 'Administración', exact: true })).toHaveCount(0)
    await expect(menu(page).getByRole('button', { name: 'Datos', exact: true })).toHaveCount(0)   // sin ninguna opción visible, el grupo no aparece
  })

  test('el servidor también lo impide: un vendedor que escribe la dirección de administración no ve la pantalla', async ({ page }) => {
    await loginOk(page, 'vendedor1')
    await page.goto('/admin/usuarios')

    await expect(page.getByRole('table', { name: 'Usuarios' })).toHaveCount(0)
  })
})
