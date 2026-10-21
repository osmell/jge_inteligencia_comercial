import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { AuthProvider } from './auth/AuthContext'
import type { AuthUser } from './auth/types'
import { ToastProvider } from './components/toast'
import ImportDetailPage from './pages/ImportDetailPage'
import ImportsPage from './pages/ImportsPage'

vi.mock('./lib/polling', () => ({ POLL_MS: 20 }))

const admin: AuthUser = {
  id: 1, username: 'admin', name: 'Ana Admin', role: { slug: 'administrador', name: 'Administrador' },
  permissions: ['imports.manage'], companies: [], branches: [],
}

const batch = (o: Record<string, unknown> = {}) => ({
  id: 7, type: 'sales', type_label: 'Ventas', status: 'validated', status_label: 'Validado', original_name: 'ventas-octubre.xlsx', file_size: 20480,
  rows_total: 3, documents_total: 2, documents_new: 2, documents_duplicate: 0, errors_total: 0, warnings_total: 0, failure_message: null,
  user: { id: 1, username: 'admin', name: 'Ana Admin' }, created_at: '2026-10-19T10:00:00Z', validated_at: '2026-10-19T10:00:05Z', finished_at: null,
  can_confirm: true, can_discard: true,
  summary: {
    rows_invalid: 0, date_from: '2026-10-01', date_to: '2026-10-03',
    totals: { net_total: '130.0000', tax_total: '15.6000', grand_total: '145.6000', cost_total: '60.0000' },
    companies: [{ id: 1, name: 'Compañía Alfa', documents: 2, lines: 3, net_total: '130.0000' }],
    new_sellers: ['María López'], new_operators: ['Caja 1'],
    preview_columns: ['Documento', 'Cliente'],
    preview: [{ row: 2, values: { Cliente: 'Cliente Uno', Documento: '0001' } }],
  },
  ...o,
})

type Call = { url: string; method: string; body: unknown }
let calls: Call[]
let routes: Record<string, (() => Response)[]>

function route(key: string, ...responses: (() => Response)[]) {
  routes[key] = responses
}
const json = (body: unknown, status = 200) => () => new Response(JSON.stringify(body), { status })

function mockApi() {
  calls = []
  vi.stubGlobal('fetch', vi.fn(async (url: string, init?: RequestInit) => {
    const path = url.replace('http://localhost:8000/api/v1', '')
    const method = init?.method ?? 'GET'
    const body = init?.body instanceof FormData ? Object.fromEntries(init.body.entries()) : init?.body ? JSON.parse(init.body as string) : undefined
    calls.push({ url: path, method, body })
    const queue = routes[`${method} ${path}`]
    if (queue) return (queue.length > 1 ? queue.shift()! : queue[0])()
    if (path === '/auth/me') return json({ data: admin })()
    if (path === '/admin/imports/types') return json({ data: [{ key: 'sales', label: 'Ventas' }] })()
    return json({ message: `sin mock para ${method} ${path}` }, 404)()
  }))
}

function renderAt(path: string) {
  return render(
    <MemoryRouter initialEntries={[path]}>
      <AuthProvider>
        <ToastProvider>
          <Routes>
            <Route path="/importaciones" element={<ImportsPage />} />
            <Route path="/importaciones/:id" element={<ImportDetailPage />} />
          </Routes>
        </ToastProvider>
      </AuthProvider>
    </MemoryRouter>,
  )
}

const xlsx = (name = 'ventas.xlsx', size = 100) => {
  const file = new File(['x'], name, { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' })
  Object.defineProperty(file, 'size', { value: size })
  return file
}

describe('importaciones', () => {
  beforeEach(() => {
    routes = {}
    document.cookie = 'XSRF-TOKEN=token; path=/'
    mockApi()
  })
  afterEach(() => vi.unstubAllGlobals())

  it('lista el historial con estado en texto y el usuario (administrador)', async () => {
    route('GET /admin/imports?page=1', json({
      data: [batch({ status: 'rejected', status_label: 'Rechazado', errors_total: 2, warnings_total: 1 }), batch({ id: 8, original_name: 'otro.xlsx' })],
      current_page: 1, last_page: 1, total: 2,
    }))
    renderAt('/importaciones')

    const table = await screen.findByRole('table', { name: 'Historial de importaciones' })
    expect(within(table).getByText('ventas-octubre.xlsx')).toBeInTheDocument()
    expect(within(table).getByText('Rechazado')).toBeInTheDocument()
    expect(within(table).getByText('Validado')).toBeInTheDocument()
    expect(within(table).getByText('(+1 avisos)')).toBeInTheDocument()
    expect(within(table).getAllByText('admin')).toHaveLength(2)
  })

  it('muestra un estado vacío', async () => {
    route('GET /admin/imports?page=1', json({ data: [], current_page: 1, last_page: 1, total: 0 }))
    renderAt('/importaciones')

    expect(await screen.findByText('Aún no hay importaciones.')).toBeInTheDocument()
  })

  it('rechaza en el navegador un archivo que no es .xlsx sin llamar al servidor', async () => {
    route('GET /admin/imports?page=1', json({ data: [], current_page: 1, last_page: 1, total: 0 }))
    renderAt('/importaciones')

    const input = await screen.findByLabelText('Archivo de Excel')
    await userEvent.upload(input, new File(['x'], 'datos.csv', { type: 'text/csv' }), { applyAccept: false })

    expect(await screen.findByRole('alert')).toHaveTextContent('El archivo debe ser un Excel (.xlsx).')
    expect(screen.getByRole('button', { name: 'Subir y validar' })).toBeDisabled()
    expect(calls.some((c) => c.method === 'POST')).toBe(false)
  })

  it('sube el archivo con su tipo y abre el detalle del lote', async () => {
    route('GET /admin/imports?page=1', json({ data: [], current_page: 1, last_page: 1, total: 0 }))
    route('POST /admin/imports', json({ data: batch({ id: 9, status: 'pending', status_label: 'En cola', can_confirm: false }) }, 201))
    route('GET /admin/imports/9', json({ data: batch({ id: 9, status: 'pending', status_label: 'En cola', can_confirm: false }) }))
    renderAt('/importaciones')

    await userEvent.upload(await screen.findByLabelText('Archivo de Excel'), xlsx('ventas.xlsx'))
    expect(screen.getByRole('button', { name: 'Subir y validar' })).toBeDisabled()   // sin tipo elegido no se puede subir
    await userEvent.selectOptions(screen.getByLabelText('Tipo de archivo'), 'sales')
    await userEvent.click(screen.getByRole('button', { name: 'Subir y validar' }))

    expect(await screen.findByText(/El archivo está en cola para validarse/)).toBeInTheDocument()
    const post = calls.find((c) => c.method === 'POST')
    expect(post?.url).toBe('/admin/imports')
    expect(post?.body).toMatchObject({ type: 'sales' })
    const sent = post?.body as { file: File }
    expect(sent.file.name).toBe('ventas.xlsx')
  })

  it('muestra el error del servidor al subir', async () => {
    route('GET /admin/imports?page=1', json({ data: [], current_page: 1, last_page: 1, total: 0 }))
    route('POST /admin/imports', json({ message: 'x', errors: { file: ['El archivo no debe superar 20480 kilobytes.'] } }, 422))
    renderAt('/importaciones')

    await userEvent.upload(await screen.findByLabelText('Archivo de Excel'), xlsx())
    await userEvent.selectOptions(screen.getByLabelText('Tipo de archivo'), 'sales')
    await userEvent.click(screen.getByRole('button', { name: 'Subir y validar' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('El archivo no debe superar 20480 kilobytes.')
  })

  it('un lote rechazado muestra sus errores por fila y columna y no se puede confirmar', async () => {
    route('GET /admin/imports/7', json({ data: batch({ status: 'rejected', status_label: 'Rechazado', can_confirm: false, errors_total: 1, warnings_total: 1 }) }))
    route('GET /admin/imports/7/errors?page=1', json({
      data: [
        { id: 1, row_number: 3, column: 'Venta + Imp', code: 'amount_mismatch', severity: 'error', message: '«Venta + Imp» (99.00) no coincide.', value: '99' },
        { id: 2, row_number: 4, column: 'Costo Tot.Vta.', code: 'cost_total_mismatch', severity: 'warning', message: 'Regla por confirmar.', value: null },
      ],
      current_page: 1, last_page: 1, total: 2,
    }))
    renderAt('/importaciones/7')

    expect(await screen.findByText(/no se puede importar/)).toBeInTheDocument()
    const table = await screen.findByRole('table', { name: 'Errores de la importación' })
    expect(within(table).getByText('Venta + Imp')).toBeInTheDocument()
    expect(within(table).getByText('Error')).toBeInTheDocument()
    expect(within(table).getByText('Advertencia')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Confirmar importación' })).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Descartar' })).toBeInTheDocument()
    // El resumen sería parcial con filas rechazadas, así que no se muestra.
    expect(screen.queryByText('Resumen')).not.toBeInTheDocument()
    expect(screen.queryByText('María López')).not.toBeInTheDocument()
  })

  it('descarga el CSV de errores desde el servidor', async () => {
    const createObjectURL = vi.fn(() => 'blob:x')
    vi.stubGlobal('URL', { ...URL, createObjectURL, revokeObjectURL: vi.fn() })
    const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => undefined)
    route('GET /admin/imports/7', json({ data: batch({ status: 'rejected', status_label: 'Rechazado', can_confirm: false, errors_total: 1 }) }))
    route('GET /admin/imports/7/errors?page=1', json({ data: [], current_page: 1, last_page: 1, total: 0 }))
    route('GET /admin/imports/7/errors.csv', () => new Response('Fila\n', { status: 200, headers: { 'content-disposition': 'attachment; filename=errores-importacion-7.csv' } }))
    renderAt('/importaciones/7')

    await userEvent.click(await screen.findByRole('button', { name: 'Descargar CSV' }))

    await waitFor(() => expect(createObjectURL).toHaveBeenCalled())
    expect(click).toHaveBeenCalled()
    expect(calls.some((c) => c.url === '/admin/imports/7/errors.csv')).toBe(true)
    click.mockRestore()
  })

  it('un lote validado muestra el resumen y se confirma con diálogo hasta quedar importado', async () => {
    route('GET /admin/imports/7', json({ data: batch() }), json({ data: batch({ status: 'importing', status_label: 'Importando', can_confirm: false, can_discard: false }) }), json({ data: batch({ status: 'imported', status_label: 'Importado', can_confirm: false, can_discard: false }) }))
    route('POST /admin/imports/7/confirm', json({ data: batch({ status: 'importing' }) }, 202))
    renderAt('/importaciones/7')

    expect(await screen.findByText('Resumen')).toBeInTheDocument()
    expect(screen.getAllByText('130,00')).toHaveLength(2)             // total neto formateado (tarjeta y tabla por compañía)
    expect(screen.getByText('Compañía Alfa')).toBeInTheDocument()
    expect(screen.getByText('María López')).toBeInTheDocument()       // altas automáticas
    expect(screen.getByText('Vista previa')).toBeInTheDocument()
    const headers = within(screen.getByRole('table', { name: 'Vista previa del archivo' })).getAllByRole('columnheader').map((h) => h.textContent)
    expect(headers).toEqual(['Fila', 'Documento', 'Cliente']) // orden de las columnas, no el de las claves del JSON

    await userEvent.click(screen.getByRole('button', { name: 'Confirmar importación' }))
    const dialog = await screen.findByRole('dialog', { name: 'Confirmar importación' })
    expect(dialog).toHaveTextContent('2 documentos nuevos')
    expect(dialog).toHaveTextContent('no se escribe nada')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Importar' }))

    expect(await screen.findByText('Importación completada.')).toBeInTheDocument()
    expect(calls.filter((c) => c.method === 'POST')).toHaveLength(1)
    expect(screen.queryByRole('button', { name: 'Confirmar importación' })).not.toBeInTheDocument()
  })

  it('un lote de clientes muestra nuevos, actualizados y sin cambios, y su confirmación lo explica', async () => {
    route('GET /admin/imports/7', json({ data: batch({
      type: 'customers', type_label: 'Clientes', documents_total: 6, documents_new: 3, documents_duplicate: 1,
      summary: { rows_invalid: 0, customers: { new: 3, updated: 2, unchanged: 1 }, preview_columns: [], preview: [] },
    }) }))
    renderAt('/importaciones/7')

    expect(await screen.findByText('Se actualizarán')).toBeInTheDocument()
    expect(screen.getByText('Nuevos').closest('div')).toHaveTextContent('3')
    expect(screen.getByText('1 sin cambios', { exact: false })).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Confirmar importación' }))
    const dialog = await screen.findByRole('dialog', { name: 'Confirmar importación' })
    expect(dialog).toHaveTextContent('Se crearán 3 clientes y se actualizarán 2')
  })

  it('un lote de productos usa el mismo resumen de archivo maestro', async () => {
    route('GET /admin/imports/7', json({ data: batch({
      type: 'products', type_label: 'Productos', documents_total: 4, documents_new: 4, documents_duplicate: 0,
      summary: { rows_invalid: 0, products: { new: 4, updated: 0, unchanged: 0 }, preview_columns: [], preview: [] },
    }) }))
    renderAt('/importaciones/7')

    expect(await screen.findByText(/inserta los productos nuevos/)).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Confirmar importación' }))
    expect(await screen.findByRole('dialog', { name: 'Confirmar importación' })).toHaveTextContent('Se crearán 4 productos')
  })

  it('un lote de facturas de crédito muestra totales, enlaces con la venta y su confirmación lo explica', async () => {
    route('GET /admin/imports/7', json({ data: batch({
      type: 'credit_invoices', type_label: 'Facturas de crédito',
      summary: {
        rows_invalid: 0, date_from: '2026-10-01', date_to: '2026-10-15', preview_columns: ['Documento'], preview: [],
        credit: {
          new: 2, unchanged: 0, linked_to_sales: 1, without_sales: 1, mismatches: 1,
          totals: { subtotal: '1200.0000', discount_total: '100.0000', tax_total: '165.0000', total: '1265.0000' },
          companies: [{ id: 1, name: 'Compañía Alfa', documents: 2 }], new_sellers: ['IRIS'], new_operators: [],
        },
      },
    }) }))
    renderAt('/importaciones/7')

    expect(await screen.findByText('Facturas de crédito del 2026-10-01 al 2026-10-15')).toBeInTheDocument()
    expect(screen.getByText('Total a crédito').closest('div')).toHaveTextContent('1265,00')
    expect(screen.getByText('Enlazadas con su venta').closest('div')).toHaveTextContent('1')
    expect(screen.getByText('Diferencias con la venta').closest('div')).toHaveTextContent('Avisos')
    expect(screen.getByText('IRIS')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Confirmar importación' }))
    const dialog = await screen.findByRole('dialog', { name: 'Confirmar importación' })
    expect(dialog).toHaveTextContent('2 facturas de crédito nuevas')
    expect(dialog).toHaveTextContent('abre su saldo en el libro de movimientos')
  })

  it('un lote de inventario inicial muestra el resumen por sucursal y su confirmación lo explica', async () => {
    route('GET /admin/imports/7', json({ data: batch({
      type: 'inventory_opening', type_label: 'Inventario inicial', documents_total: 3, documents_new: 3, documents_duplicate: 1,
      summary: { rows_invalid: 0, preview_columns: [], preview: [], inventory: { new: 3, unchanged: 1, conflicts: 0, branches: [{ id: 2, name: 'Central', products: 3, cutoff: '2026-09-30' }] } },
    }) }))
    renderAt('/importaciones/7')

    expect(await screen.findByText('Con conflicto')).toBeInTheDocument()
    expect(screen.getByRole('table', { name: 'Resumen por sucursal' })).toHaveTextContent('2026-09-30')
    await userEvent.click(screen.getByRole('button', { name: 'Confirmar importación' }))
    const dialog = await screen.findByRole('dialog', { name: 'Confirmar importación' })
    expect(dialog).toHaveTextContent('Se cargarán 3 filas de inventario inicial')
    expect(dialog).toHaveTextContent('un cambio posterior se registra como ajuste')
  })

  it('un lote de unidades y equivalencias resume lo que fija y actualiza', async () => {
    route('GET /admin/imports/7', json({ data: batch({
      type: 'product_units', type_label: 'Unidades y equivalencias', documents_total: 5, documents_new: 5, documents_duplicate: 0,
      summary: { rows_invalid: 0, preview_columns: [], preview: [], units: { bases_set: 2, new: 3, updated: 1, unchanged: 0 } },
    }) }))
    renderAt('/importaciones/7')

    expect(await screen.findByText('Equivalencias nuevas')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Confirmar importación' }))
    expect(await screen.findByRole('dialog', { name: 'Confirmar importación' })).toHaveTextContent('Se fijarán 2 unidades base, se crearán 3 equivalencias y se actualizarán 1')
  })

  it('un lote de entradas de inventario resume documentos, líneas y sucursales sin inventario inicial', async () => {
    route('GET /admin/imports/7', json({ data: batch({
      type: 'inventory_entries', type_label: 'Entradas de inventario', documents_total: 4, documents_new: 3, documents_duplicate: 1,
      summary: { rows_invalid: 0, preview_columns: [], preview: [], entries: { new: 3, unchanged: 1, lines: 9, skipped_before_cutoff: 2, branches_without_opening: ['Norte'] } },
    }) }))
    renderAt('/importaciones/7')

    expect(await screen.findByText('Omitidos por el corte')).toBeInTheDocument()
    expect(screen.getByText(/Sin inventario inicial en/)).toHaveTextContent('Norte')
    await userEvent.click(screen.getByRole('button', { name: 'Confirmar importación' }))
    const dialog = await screen.findByRole('dialog', { name: 'Confirmar importación' })
    expect(dialog).toHaveTextContent('Se cargarán 3 documentos de entrada')
    expect(dialog).toHaveTextContent('el IVA no entra al costo')
  })

  it('actualiza solo mientras el lote se procesa', async () => {
    route('GET /admin/imports/7', json({ data: batch({ status: 'validating', status_label: 'Validando', can_confirm: false }) }), json({ data: batch() }))
    renderAt('/importaciones/7')

    expect(await screen.findByText('Validando el archivo…')).toBeInTheDocument()
    expect(await screen.findByRole('button', { name: 'Confirmar importación' })).toBeInTheDocument()

    const before = calls.filter((c) => c.url === '/admin/imports/7').length
    await new Promise((r) => setTimeout(r, 120))
    expect(calls.filter((c) => c.url === '/admin/imports/7').length).toBe(before) // ya no consulta
  })

  it('descarta un lote tras confirmar en el diálogo', async () => {
    route('GET /admin/imports/7', json({ data: batch() }), json({ data: batch({ status: 'discarded', status_label: 'Descartado', can_confirm: false, can_discard: false }) }))
    route('POST /admin/imports/7/discard', json({ data: batch({ status: 'discarded' }) }))
    renderAt('/importaciones/7')

    await userEvent.click(await screen.findByRole('button', { name: 'Descartar' }))
    await userEvent.click(within(await screen.findByRole('dialog', { name: 'Descartar lote' })).getByRole('button', { name: 'Descartar' }))

    expect(await screen.findByText(/fue descartado/)).toBeInTheDocument()
    expect(calls.some((c) => c.url === '/admin/imports/7/discard' && c.method === 'POST')).toBe(true)
  })

  it('muestra el conflicto si el servidor rechaza la confirmación', async () => {
    route('GET /admin/imports/7', json({ data: batch() }))
    route('POST /admin/imports/7/confirm', json({ message: 'Solo se puede confirmar un lote validado que no se haya confirmado antes.' }, 409))
    renderAt('/importaciones/7')

    await userEvent.click(await screen.findByRole('button', { name: 'Confirmar importación' }))
    await userEvent.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Importar' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('Solo se puede confirmar un lote validado')
  })

  it('un lote fallido muestra el mensaje del servidor', async () => {
    route('GET /admin/imports/7', json({ data: batch({ status: 'failed', status_label: 'Fallido', can_confirm: false, failure_message: 'No se escribió ningún dato.' }) }))
    renderAt('/importaciones/7')

    expect(await screen.findByText('No se escribió ningún dato.')).toBeInTheDocument()
  })
})
