import { expect, test } from '@playwright/test'
import { loginOk } from './helpers'

test('administración lista compañías y usuarios, y abre el formulario de un usuario', async ({ page }) => {
  await loginOk(page, 'admin')

  await page.goto('/admin/companias')
  await expect(page.getByRole('table', { name: 'Compañías' })).toBeVisible()
  await expect(page.getByRole('row').nth(1)).toBeVisible()

  await page.goto('/admin/usuarios')
  const table = page.getByRole('table', { name: 'Usuarios' })
  await expect(table).toBeVisible()
  await expect(table.getByText('vendedor1', { exact: true })).toBeVisible()

  await page.getByLabel('Buscar usuarios').fill('supervisor')
  await expect(table.getByText('vendedor1', { exact: true })).toHaveCount(0)

  await page.getByRole('button', { name: 'Editar usuario supervisor' }).click()
  const dialog = page.getByRole('dialog')
  await expect(dialog.getByLabel('Nombre de usuario')).toHaveValue('supervisor')
  await dialog.getByRole('button', { name: 'Cancelar' }).click()
  await expect(dialog).toHaveCount(0)
})
