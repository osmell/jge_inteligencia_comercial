import { expect, test } from '@playwright/test'
import { loginOk, menu, openReport, reportsList } from './helpers'

test.describe('Reporte de vendedores', () => {
  test('administración evalúa a los vendedores y abre el detalle de facturación', async ({ page }) => {
    await loginOk(page, 'admin')
    await openReport(page, 'Vendedores')

    await expect(page.getByRole('heading', { name: 'Reporte de vendedores' })).toBeVisible()
    await page.getByLabel('Periodo').selectOption('ytd')
    const table = page.getByRole('table', { name: 'Evaluación de vendedores' })
    await expect(table).toBeVisible()
    await expect(page.getByRole('alert')).toHaveCount(0)

    // El contraste entre la venta consolidada y la suma de los vendedores se informa siempre.
    await expect(page.getByText(/coincide con la suma de los vendedores|no coincide con la suma de los vendedores/)).toBeVisible()

    const first = table.getByRole('button', { name: /Ver la facturación de/ }).first()
    await first.click()
    await expect(page.getByRole('dialog')).toBeVisible()
    await expect(page.getByRole('table', { name: /Documentos facturados por/ })).toBeVisible()
  })

  test('un vendedor no ve el reporte ni puede abrirlo escribiendo la dirección', async ({ page }) => {
    await loginOk(page, 'vendedor1')
    await expect(menu(page).getByRole('button', { name: 'Reportes' })).toHaveCount(0)

    await page.goto('/reportes/vendedores')

    await expect(page.getByRole('table', { name: 'Evaluación de vendedores' })).toHaveCount(0)
  })

  test('gerencia y supervisión sí lo ven', async ({ page }) => {
    for (const who of ['gerente', 'supervisor'] as const) {
      await loginOk(page, who)
      await expect(menu(page).getByRole('button', { name: 'Reportes' })).toBeVisible()
      await page.getByRole('button', { name: 'Cerrar sesión' }).click()
      await expect(page).toHaveURL(/\/login/)
    }
  })
})

test.describe('Reporte de operadores', () => {
  test('administración evalúa a los operadores y abre su detalle', async ({ page }) => {
    await loginOk(page, 'admin')
    await openReport(page, 'Operadores')

    await expect(page.getByRole('heading', { name: 'Reporte de operadores' })).toBeVisible()
    await page.getByLabel('Periodo').selectOption('ytd')
    const table = page.getByRole('table', { name: 'Evaluación de operadores' })
    await expect(table).toBeVisible()
    await expect(page.getByRole('alert')).toHaveCount(0)
    await expect(page.getByText(/coincide con la suma de los operadores|no coincide con la suma de los operadores/)).toBeVisible()

    await table.getByRole('button', { name: /Ver el detalle de/ }).first().click()
    await expect(page.getByRole('table', { name: /Documentos facturados por/ })).toBeVisible()
    await page.getByRole('tab', { name: 'A quién y cuándo factura' }).click()
    await expect(page.getByRole('table', { name: 'Vendedores a los que factura' })).toBeVisible()
    await expect(page.getByText('Documentos por día de la semana')).toBeVisible()
  })

  test('un vendedor no lo ve ni puede abrirlo escribiendo la dirección', async ({ page }) => {
    await loginOk(page, 'vendedor1')
    await expect(menu(page).getByRole('button', { name: 'Reportes' })).toHaveCount(0)

    await page.goto('/reportes/operadores')

    await expect(page.getByRole('table', { name: 'Evaluación de operadores' })).toHaveCount(0)
  })
})

test.describe('Reporte de sucursales', () => {
  test('administración evalúa las sucursales, ve la mezcla de categorías y abre su facturación', async ({ page }) => {
    await loginOk(page, 'admin')
    await openReport(page, 'Sucursales')

    await expect(page.getByRole('heading', { name: 'Reporte de sucursales' })).toBeVisible()
    await page.getByLabel('Periodo').selectOption('ytd')
    const table = page.getByRole('table', { name: 'Evaluación de sucursales' })
    await expect(table).toBeVisible()
    await expect(page.getByRole('alert')).toHaveCount(0)
    await expect(page.getByText(/coincide con la suma de las sucursales|no coincide con la suma de las sucursales/)).toBeVisible()
    await expect(page.getByRole('table', { name: 'Mezcla de categorías por sucursal' })).toBeVisible()

    await table.getByRole('button', { name: /Ver la facturación de/ }).first().click()
    await expect(page.getByRole('table', { name: /Documentos facturados en/ })).toBeVisible()
  })

  test('un vendedor no lo ve ni puede abrirlo escribiendo la dirección', async ({ page }) => {
    await loginOk(page, 'vendedor1')
    await expect(menu(page).getByRole('button', { name: 'Reportes' })).toHaveCount(0)

    await page.goto('/reportes/sucursales')

    await expect(page.getByRole('table', { name: 'Evaluación de sucursales' })).toHaveCount(0)
  })
})

test.describe('Mezcla de productos', () => {
  test('administración ve lo más facturado, cambia el nivel y abre lo que una persona no vende', async ({ page }) => {
    await loginOk(page, 'admin')
    await openReport(page, 'Productos')

    await expect(page.getByRole('heading', { name: 'Mezcla de productos' })).toBeVisible()
    await page.getByLabel('Periodo').selectOption('ytd')
    await expect(page.getByRole('table', { name: 'Lo más facturado por categoría' })).toBeVisible()
    await expect(page.getByRole('alert')).toHaveCount(0)
    await page.getByLabel('Ver la venta por').selectOption('product')
    await expect(page.getByRole('table', { name: 'Lo más facturado por producto' })).toBeVisible()

    await page.getByLabel('Evaluar a').selectOption('operator')
    await expect(page.getByRole('table', { name: 'Amplitud de catálogo por operador' })).toBeVisible()
    await page.getByRole('table', { name: 'Amplitud de catálogo por operador' }).getByRole('button', { name: /Ver el detalle de/ }).first().click()
    await expect(page.getByRole('table', { name: 'Categorías sin venta' })).toBeVisible()
  })

  test('un vendedor no lo ve ni puede abrirlo escribiendo la dirección', async ({ page }) => {
    await loginOk(page, 'vendedor1')
    await expect(menu(page).getByRole('button', { name: 'Reportes' })).toHaveCount(0)

    await page.goto('/reportes/productos')

    await expect(page.getByRole('table', { name: 'Lo más facturado por categoría' })).toHaveCount(0)
  })
})

test.describe('Conciliación', () => {
  test('administración concilia el periodo y consulta el detalle de facturación de un vendedor', async ({ page }) => {
    await loginOk(page, 'admin')
    await openReport(page, 'Conciliación')

    await expect(page.getByRole('heading', { name: 'Conciliación' })).toBeVisible()
    await page.getByLabel('Periodo').selectOption('ytd')
    await expect(page.getByRole('table', { name: 'Sumas por dimensión contra el consolidado' })).toBeVisible()
    await expect(page.getByRole('table', { name: 'Integridad de los documentos de venta' })).toBeVisible()
    await expect(page.getByRole('alert')).toHaveCount(0)

    await page.getByLabel('Vendedor', { exact: true }).selectOption({ index: 1 })
    await expect(page.getByRole('table', { name: 'Documentos facturados' })).toBeVisible()
  })

  test('un vendedor no la ve ni puede abrirla escribiendo la dirección', async ({ page }) => {
    await loginOk(page, 'vendedor1')
    await expect(menu(page).getByRole('button', { name: 'Reportes' })).toHaveCount(0)

    await page.goto('/reportes/conciliacion')

    await expect(page.getByRole('table', { name: 'Sumas por dimensión contra el consolidado' })).toHaveCount(0)
  })
})

test.describe('Menú desplegable «Reportes»', () => {
  test('empieza cerrado, se abre con el botón y recuerda que se dejó abierto', async ({ page }) => {
    await loginOk(page, 'admin')
    const group = menu(page).getByRole('button', { name: 'Reportes' })

    await expect(group).toHaveAttribute('aria-expanded', 'false')
    await expect(reportsList(page).getByRole('link', { name: 'Vendedores', exact: true })).toHaveCount(0)

    await group.click()
    await expect(group).toHaveAttribute('aria-expanded', 'true')
    for (const option of ['Vendedores', 'Operadores', 'Sucursales', 'Productos', 'Conciliación']) {
      await expect(reportsList(page).getByRole('link', { name: option, exact: true })).toBeVisible()
    }

    await page.reload()
    await expect(menu(page).getByRole('button', { name: 'Reportes' })).toHaveAttribute('aria-expanded', 'true')
  })

  test('al entrar directo a un reporte el menú queda abierto y marca la opción', async ({ page }) => {
    await loginOk(page, 'admin')
    await page.goto('/reportes/operadores')

    await expect(menu(page).getByRole('button', { name: 'Reportes' })).toHaveAttribute('aria-expanded', 'true')
    await expect(reportsList(page).getByRole('link', { name: 'Operadores', exact: true })).toHaveAttribute('aria-current', 'page')
    await expect(page.getByRole('heading', { name: 'Reporte de operadores' })).toBeVisible()
  })

  test('la barra de comandos encuentra los reportes por su nombre completo', async ({ page }) => {
    await loginOk(page, 'admin')
    await page.keyboard.press('/')
    await page.getByRole('combobox', { name: /Ir a una pantalla/ }).fill('reporte de vend')

    await expect(page.getByRole('option', { name: /Reporte de vendedores/ })).toBeVisible()
  })
})

test.describe('Salidas de los reportes', () => {
  test('cada reporte ofrece imprimir, Excel, PDF y correo', async ({ page }) => {
    await loginOk(page, 'admin')
    for (const option of ['Vendedores', 'Operadores', 'Sucursales', 'Productos', 'Conciliación'] as const) {
      await openReport(page, option)
      const actions = page.getByRole('group', { name: 'Salidas del reporte' })
      for (const name of ['Imprimir', 'Excel', 'PDF', 'Enviar por correo']) await expect(actions.getByRole('button', { name })).toBeEnabled()
    }
  })

  test('descarga el reporte en Excel y en PDF con el periodo en el nombre', async ({ page }) => {
    await loginOk(page, 'admin')
    await openReport(page, 'Vendedores')
    await expect(page.getByRole('heading', { name: 'Reporte de vendedores' })).toBeVisible()
    await page.getByLabel('Periodo').selectOption('ytd')
    await expect(page.getByRole('table', { name: 'Evaluación de vendedores' })).toBeVisible()

    const excel = page.waitForEvent('download')
    await page.getByRole('button', { name: 'Excel' }).click()
    expect((await excel).suggestedFilename()).toMatch(/^reporte-vendedores-\d{4}-\d{2}-\d{2}_\d{4}-\d{2}-\d{2}\.xlsx$/)

    const pdf = page.waitForEvent('download')
    await page.getByRole('button', { name: 'PDF' }).click()
    expect((await pdf).suggestedFilename()).toMatch(/\.pdf$/)
  })

  test('al imprimir no salen el menú ni los controles, solo el reporte', async ({ page }) => {
    await loginOk(page, 'admin')
    await openReport(page, 'Sucursales')
    await expect(page.getByRole('heading', { name: 'Reporte de sucursales' })).toBeVisible()
    await page.getByLabel('Periodo').selectOption('ytd')
    await expect(page.getByRole('table', { name: 'Evaluación de sucursales' })).toBeVisible()

    await page.emulateMedia({ media: 'print' })

    await expect(menu(page)).toBeHidden()
    await expect(page.getByRole('group', { name: 'Salidas del reporte' })).toBeHidden()
    await expect(page.getByLabel('Periodo')).toBeHidden()
    await expect(page.getByRole('table', { name: 'Evaluación de sucursales' })).toBeVisible()
    expect((await page.pdf({ landscape: true })).length).toBeGreaterThan(1500)
  })

  test('el correo exige revisar y confirmar los destinatarios, y sin correo configurado no envía nada', async ({ page }) => {
    await loginOk(page, 'admin')
    await openReport(page, 'Operadores')
    await page.getByRole('button', { name: 'Enviar por correo' }).click()

    const dialog = page.getByRole('dialog')
    await expect(dialog.getByRole('button', { name: 'Revisar destinatarios' })).toBeDisabled()
    await dialog.getByLabel('Para').fill('jefe@ejemplo.com')
    await dialog.getByRole('button', { name: 'Revisar destinatarios' }).click()

    await expect(dialog.getByRole('region', { name: 'Destinatarios' })).toContainText('jefe@ejemplo.com')
    await expect(dialog.getByRole('button', { name: 'Enviar ahora' })).toBeDisabled()
    await dialog.getByRole('checkbox', { name: /Confirmo/ }).check()
    await dialog.getByRole('button', { name: 'Enviar ahora' }).click()

    // La instalación de pruebas no tiene el correo configurado: se dice, y no se intenta enviar por otra vía.
    await expect(dialog.getByText(/El correo no está activado o configurado/)).toBeVisible()
  })
})
