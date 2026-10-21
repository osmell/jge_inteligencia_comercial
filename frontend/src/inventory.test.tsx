import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { AuthProvider } from './auth/AuthContext'
import InventoryKardexPage from './pages/InventoryKardexPage'
import InventoryMovementsPage from './pages/InventoryMovementsPage'
import InventoryRotationPage from './pages/InventoryRotationPage'
import InventoryShortfallsPage from './pages/InventoryShortfallsPage'
import InventoryStockPage from './pages/InventoryStockPage'
import InventoryUnitsPage from './pages/InventoryUnitsPage'

let permissions: string[]
let costsVisible: boolean
let urls: string[]
let puts: { path: string; body: Record<string, unknown> }[]
let posts: { path: string; body: Record<string, unknown> }[]

const units = [
  { id: 1, code: 'galon', name: 'Galón', active: true },
  { id: 2, code: 'cubeta', name: 'Cubeta', active: true },
  { id: 3, code: '1_16', name: '1/16', active: true },
]

beforeEach(() => {
  permissions = ['inventory.view', 'inventory.manage']
  puts = []
  posts = []
  urls = []
  costsVisible = false
  document.cookie = 'XSRF-TOKEN=x'
  vi.stubGlobal('fetch', vi.fn(async (url: string, init?: RequestInit) => {
    const path = url.replace('http://localhost:8000/api/v1', '')
    if (init?.method === 'POST' && (path === '/inventory/movements' || path === '/inventory/transfers' || /\/reverse$/.test(path))) {
      posts.push({ path, body: JSON.parse(String(init.body)) })
      return new Response(JSON.stringify({ data: { id: 1, reference: 'TR-1' } }), { status: 201 })
    }
    if (init?.method === 'POST') {
      puts.push({ path, body: {} })
      return new Response(JSON.stringify({ data: { applied: '2', missing: '1', resolved: false } }), { status: 200 })
    }
    if (init?.method === 'PUT') {
      puts.push({ path, body: JSON.parse(String(init.body)) })
      return new Response(JSON.stringify({ data: {} }), { status: 200 })
    }
    urls.push(path)
    let body: unknown = { data: [] }
    if (path.startsWith('/inventory/rotation')) {
      body = {
        data: [
          { company_id: 1, branch_id: 2, branch: 'Central', product: { id: 7, code: 'ACE-1', name: 'Aceite 20W50' }, stock: '10', sold: '90', daily_demand: '1', coverage_days: '10', rotation: '1.64', min: '22', max: '52', suggested: '42', last_sale: '2026-10-10', days_since_sale: 10, status: 'below_min', ...(costsVisible ? { value: '100.00' } : {}) },
          { company_id: 1, branch_id: 2, branch: 'Central', product: { id: 8, code: 'FIL-9', name: 'Filtro' }, stock: '20', sold: '0', daily_demand: '0', coverage_days: null, rotation: null, min: '0', max: '0', suggested: null, last_sale: null, days_since_sale: null, status: 'no_rotation', ...(costsVisible ? { value: '60.00' } : {}) },
        ],
        meta: { page: 1, has_more: false, total: 2, counts: { out_of_stock: 0, below_min: 1, no_rotation: 1, above_max: 0, ok: 0 }, params: { demand_days: 90, no_movement_days: 60, reorder_days: 15, safety_days: 7, cycle_days: 30 } },
      }
    } else if (path.startsWith('/inventory/movements')) {
      body = { data: [
        { id: 11, date: '2026-10-10', type: 'adjustment_out', reason: 'damage', branch: 'Central', product: { id: 7, code: 'ACE-1', name: 'Aceite 20W50' }, quantity: '-2', entered: '2', entered_unit: null, reference: 'Ajuste de salida', note: 'Envase roto', user: 'Ana', reversed: false, reversible: true },
        { id: 12, date: '2026-10-11', type: 'transfer_out', reason: null, branch: 'Central', product: { id: 7, code: 'ACE-1', name: 'Aceite 20W50' }, quantity: '-4', entered: '4', entered_unit: null, reference: 'TR-ABC', note: null, user: 'Ana', reversed: true, reversible: false },
      ], meta: { page: 1, has_more: false, costs_visible: false } }
    } else if (path.startsWith('/inventory/shortfalls')) {
      body = { data: [{ id: 5, company_id: 1, branch_id: 2, branch: 'Central', issued_on: '2026-10-05', document: 'F-1', product: { id: 7, code: 'ACE-1', name: 'Aceite 20W50' }, requested: '4', applied: '1', missing: '3', reason: 'insufficient', resolved: false }], meta: { page: 1, has_more: false, open_total: 1 } }
    } else if (path.startsWith('/inventory/stock')) {
      body = {
        data: [
          { company_id: 1, company: 'Alfa', branch_id: 2, branch: 'Central', product: { id: 7, code: 'ACE-1', name: 'Aceite 20W50' }, unit: 'Galón', quantity: '6.5', reconciled: true, ...(costsVisible ? { value: '65.0000', average_cost: '10.0000' } : {}) },
          { company_id: 1, company: 'Alfa', branch_id: 3, branch: 'Norte', product: { id: 7, code: 'ACE-1', name: 'Aceite 20W50' }, unit: 'Galón', quantity: '3', reconciled: false, ...(costsVisible ? { value: '45.0000', average_cost: '15.0000' } : {}) },
        ],
        meta: { page: 1, has_more: false, costs_visible: costsVisible, open_shortfalls: 2, totals: { products: 2, ...(costsVisible ? { value: '110.00' } : {}) } },
      }
    } else if (path.startsWith('/inventory/kardex')) {
      body = {
        data: {
          opening: { quantity: '0', ...(costsVisible ? { value: '0.0000' } : {}) }, truncated: false, reconciled: true,
          closing: { quantity: '6', ...(costsVisible ? { value: '60.0000' } : {}) },
          rows: [
            { id: 1, date: '2026-09-01', type: 'opening', quantity: '10', entered: '2', entered_unit: 'Cubeta', reference: 'DEMO', note: 'Toma de prueba', user: 'Ana', reverses_id: null, reversed: false, balance: '10', ...(costsVisible ? { value: '100.0000', balance_value: '100.0000' } : {}) },
            { id: 2, date: '2026-09-10', type: 'sale', quantity: '-4', entered: '4', entered_unit: null, reference: null, note: null, user: null, reverses_id: null, reversed: true, balance: '6', ...(costsVisible ? { value: '-40.0000', balance_value: '60.0000' } : {}) },
          ],
        },
        meta: { product: { id: 7, code: 'ACE-1', name: 'Aceite 20W50', unit: 'Galón' }, branch: 'Central', costs_visible: costsVisible },
      }
    }
    if (path === '/auth/me') body = { data: { id: 1, username: 'a', name: 'Ana', role: { slug: 'administrador', name: 'Administrador' }, permissions, companies: [], branches: [] } }
    else if (path === '/companies') body = { data: [{ id: 1, code: 'A', name: 'Alfa', is_active: true }] }
    else if (path === '/branches') body = { data: [{ id: 2, company_id: 1, code: 'C', name: 'Central', is_active: true }, { id: 3, company_id: 1, code: 'N', name: 'Norte', is_active: true }] }
    else if (path === '/inventory/units') body = { data: units }
    else if (path === '/inventory/products/7/units') body = { data: { id: 7, company_id: 1, code: 'ACE-1', name: 'Aceite 20W50', base_unit: { id: 1, code: 'galon', name: 'Galón' }, equivalences: [{ unit_id: 2, unit: 'Cubeta', factor: '5' }] } }
    else if (path === '/inventory/products?q=ace&company_id=1') body = { data: [{ id: 7, company_id: 1, code: 'ACE-1', name: 'Aceite 20W50', base_unit: { id: 1, code: 'galon', name: 'Galón' }, equivalences_count: 1 }] }
    else if (path.startsWith('/inventory/products')) {
      body = { data: [{ id: 7, company_id: 1, code: 'ACE-1', name: 'Aceite 20W50', base_unit: { id: 1, code: 'galon', name: 'Galón' }, equivalences_count: 1 }, { id: 8, company_id: 1, code: 'FIL-9', name: 'Filtro', base_unit: null, equivalences_count: 0 }] }
    }
    return new Response(JSON.stringify(body), { status: 200 })
  }))
})
afterEach(() => vi.unstubAllGlobals())

const renderPage = () => render(<AuthProvider><MemoryRouter><InventoryUnitsPage /></MemoryRouter></AuthProvider>)

describe('unidades y equivalencias', () => {
  it('lista las unidades y los productos con su unidad base', async () => {
    renderPage()

    const units = await screen.findByRole('table', { name: 'Unidades de medida' })
    expect(units).toHaveTextContent('Cubeta')
    const products = await screen.findByRole('table', { name: 'Productos y unidad base' })
    await waitFor(() => expect(products).toHaveTextContent('Aceite 20W50'))
    expect(products).toHaveTextContent('Sin definir')
  })

  it('edita la unidad base y las equivalencias de un producto', async () => {
    const u = userEvent.setup()
    renderPage()

    await u.click(await screen.findByRole('button', { name: 'Aceite 20W50' }))
    const factor = await screen.findByLabelText('Vale (Galón)')
    expect(factor).toHaveValue('5')
    await u.clear(factor)
    await u.type(factor, '5.5')
    await u.click(screen.getByRole('button', { name: 'Agregar equivalencia' }))
    await u.selectOptions(screen.getByLabelText('Unidad', { selector: '#eq-unit-1' }), '3')
    await u.type(screen.getByLabelText('Vale (Galón)', { selector: '#eq-factor-1' }), '0.0625')
    await u.click(screen.getByRole('button', { name: 'Guardar' }))

    await waitFor(() => expect(puts).toHaveLength(1))
    expect(puts[0].path).toBe('/inventory/products/7/units')
    expect(puts[0].body).toEqual({ base_unit_id: 1, equivalences: [{ unit_id: 2, factor: '5.5' }, { unit_id: 3, factor: '0.0625' }] })
  })

  it('con solo consulta no ofrece editar', async () => {
    permissions = ['inventory.view']
    const u = userEvent.setup()
    renderPage()

    await u.click(await screen.findByRole('button', { name: 'Aceite 20W50' }))
    await screen.findByLabelText('Vale (Galón)')
    expect(screen.queryByRole('button', { name: 'Agregar equivalencia' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Guardar' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Agregar unidad' })).not.toBeInTheDocument()
  })

  it('las existencias no muestran costos sin el permiso y avisan de sucursales sin inventario inicial', async () => {
    render(<AuthProvider><MemoryRouter><InventoryStockPage /></MemoryRouter></AuthProvider>)

    const table = await screen.findByRole('table', { name: 'Existencias por sucursal' })
    await waitFor(() => expect(table).toHaveTextContent('Aceite 20W50'))
    expect(table).toHaveTextContent('6,5')
    expect(table).toHaveTextContent('Sin inventario inicial')
    expect(table).not.toHaveTextContent('Costo promedio')
    expect(screen.queryByText('Valor del inventario')).not.toBeInTheDocument()
    expect(screen.getByText(/Hay sucursales sin inventario inicial/)).toBeInTheDocument()
    expect(within(table).getByRole('link', { name: 'Kárdex de Aceite 20W50 en Central' })).toHaveAttribute('href', '/inventario/kardex?company_id=1&branch_id=2&product_id=7')
  })

  it('con el permiso de costos muestra costo promedio y valor del inventario', async () => {
    costsVisible = true
    render(<AuthProvider><MemoryRouter><InventoryStockPage /></MemoryRouter></AuthProvider>)

    const table = await screen.findByRole('table', { name: 'Existencias por sucursal' })
    await waitFor(() => expect(table).toHaveTextContent('Costo promedio'))
    expect(table).toHaveTextContent('65,00')
    expect(screen.getByText('Valor del inventario').closest('div')).toHaveTextContent('110,00')
  })

  it('envía los filtros de existencias al servidor', async () => {
    const u = userEvent.setup()
    render(<AuthProvider><MemoryRouter><InventoryStockPage /></MemoryRouter></AuthProvider>)

    await screen.findByRole('table', { name: 'Existencias por sucursal' })
    await u.selectOptions(screen.getByLabelText('Compañía'), '1')
    await u.selectOptions(screen.getByLabelText('Estado'), 'all')
    await u.type(screen.getByLabelText('Buscar producto'), 'aceite')
    await waitFor(() => {
      const last = urls.filter((x) => x.startsWith('/inventory/stock')).at(-1) ?? ''
      expect(last).toContain('company_id=1')
      expect(last).toContain('status=all')
      expect(last).toContain('q=aceite')
    })
  })

  it('el kárdex muestra el saldo corrido, lo capturado en su unidad y lo revertido', async () => {
    render(<AuthProvider><MemoryRouter initialEntries={['/inventario/kardex?company_id=1&branch_id=2&product_id=7']}><Routes><Route path="/inventario/kardex" element={<InventoryKardexPage />} /></Routes></MemoryRouter></AuthProvider>)

    expect(await screen.findByRole('heading', { name: 'Kárdex: Aceite 20W50' })).toBeInTheDocument()
    const table = await screen.findByRole('table', { name: 'Movimientos del producto' })
    await waitFor(() => expect(table).toHaveTextContent('Inventario inicial'))
    expect(table).toHaveTextContent('2 Cubeta')
    expect(table).toHaveTextContent('Revertido')
    expect(table).toHaveTextContent('Toma de prueba')
    expect(table).not.toHaveTextContent('Valor del saldo')
  })

  it('el kárdex pide elegir un producto si falta el filtro', async () => {
    render(<AuthProvider><MemoryRouter initialEntries={['/inventario/kardex']}><InventoryKardexPage /></MemoryRouter></AuthProvider>)

    expect(await screen.findByText(/Elige un producto desde la pantalla de existencias/)).toBeInTheDocument()
  })

  it('las existencias avisan de los faltantes por conciliar', async () => {
    render(<AuthProvider><MemoryRouter><InventoryStockPage /></MemoryRouter></AuthProvider>)

    expect(await screen.findByText(/ventas con faltante por conciliar/)).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Ver faltantes' })).toHaveAttribute('href', '/inventario/faltantes')
  })

  it('lista los faltantes y permite conciliar con el permiso de gestión', async () => {
    const u = userEvent.setup()
    render(<AuthProvider><MemoryRouter><InventoryShortfallsPage /></MemoryRouter></AuthProvider>)

    const table = await screen.findByRole('table', { name: 'Faltantes por conciliar' })
    await waitFor(() => expect(table).toHaveTextContent('F-1'))
    expect(table).toHaveTextContent('Existencias insuficientes')
    await u.click(screen.getByRole('button', { name: /Conciliar faltante de Aceite 20W50/ }))

    await waitFor(() => expect(puts).toEqual([{ path: '/inventory/shortfalls/5/reconcile', body: {} }]))
  })

  it('sin permiso de gestión los faltantes son de solo consulta', async () => {
    permissions = ['inventory.view']
    render(<AuthProvider><MemoryRouter><InventoryShortfallsPage /></MemoryRouter></AuthProvider>)

    await screen.findByRole('table', { name: 'Faltantes por conciliar' })
    await waitFor(() => expect(screen.getByRole('table', { name: 'Faltantes por conciliar' })).toHaveTextContent('F-1'))
    expect(screen.queryByRole('button', { name: /Conciliar/ })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Revisar ventas pendientes' })).not.toBeInTheDocument()
  })

  it('lista los movimientos, marca lo revertido y solo ofrece revertir lo que se puede', async () => {
    const u = userEvent.setup()
    render(<AuthProvider><MemoryRouter><InventoryMovementsPage /></MemoryRouter></AuthProvider>)

    const table = await screen.findByRole('table', { name: 'Movimientos de inventario' })
    await waitFor(() => expect(table).toHaveTextContent('Envase roto'))
    expect(table).toHaveTextContent('Daño')
    expect(table).toHaveTextContent('Revertido')
    expect(screen.getAllByRole('button', { name: /^Revertir/ })).toHaveLength(1)

    await u.click(screen.getByRole('button', { name: /Revertir Ajuste \(salida\) de Aceite 20W50/ }))
    const dialog = await screen.findByRole('dialog')
    await u.type(within(dialog).getByLabelText('Motivo de la reversa'), 'Error de captura')
    await u.click(within(dialog).getByRole('button', { name: 'Guardar' }))
    await waitFor(() => expect(posts).toEqual([{ path: '/inventory/movements/11/reverse', body: { note: 'Error de captura' } }]))
  })

  it('registra un ajuste con su motivo', async () => {
    const u = userEvent.setup()
    render(<AuthProvider><MemoryRouter><InventoryMovementsPage /></MemoryRouter></AuthProvider>)

    await u.click(await screen.findByRole('button', { name: 'Nuevo movimiento' }))
    const dialog = await screen.findByRole('dialog')
    await u.selectOptions(within(dialog).getByLabelText('Compañía'), '1')
    await u.selectOptions(within(dialog).getByLabelText('Sucursal'), '2')
    await u.type(within(dialog).getByLabelText('Buscar producto'), 'ace')
    await within(dialog).findByRole('option', { name: /ACE-1/ })
    await u.selectOptions(within(dialog).getByLabelText('Producto'), '7')
    await u.type(within(dialog).getByLabelText('Cantidad'), '2')
    await u.selectOptions(within(dialog).getByLabelText('Motivo'), 'damage')
    await u.type(within(dialog).getByLabelText('Nota (opcional)'), 'Envase roto')
    await u.click(within(dialog).getByRole('button', { name: 'Guardar' }))

    await waitFor(() => expect(posts).toHaveLength(1))
    expect(posts[0].path).toBe('/inventory/movements')
    expect(posts[0].body).toMatchObject({ type: 'adjustment_out', company_id: 1, branch_id: 2, product_id: 7, quantity: '2', reason: 'damage', note: 'Envase roto', unit_id: null })
  })

  it('el traslado pide origen y destino y no ofrece motivo', async () => {
    const u = userEvent.setup()
    render(<AuthProvider><MemoryRouter><InventoryMovementsPage /></MemoryRouter></AuthProvider>)

    await u.click(await screen.findByRole('button', { name: 'Nuevo movimiento' }))
    const dialog = await screen.findByRole('dialog')
    await u.selectOptions(within(dialog).getByLabelText('Tipo de movimiento'), 'transfer')
    expect(within(dialog).getByLabelText('Sucursal de destino')).toBeInTheDocument()
    expect(within(dialog).queryByLabelText('Motivo')).not.toBeInTheDocument()
  })

  it('sin permiso de gestión los movimientos son de consulta', async () => {
    permissions = ['inventory.view']
    render(<AuthProvider><MemoryRouter><InventoryMovementsPage /></MemoryRouter></AuthProvider>)

    await waitFor(() => expect(screen.getByRole('table', { name: 'Movimientos de inventario' })).toHaveTextContent('Envase roto'))
    expect(screen.queryByRole('button', { name: 'Nuevo movimiento' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /^Revertir/ })).not.toBeInTheDocument()
  })

  it('la rotación muestra las alertas, los criterios y la sugerencia de reposición', async () => {
    render(<AuthProvider><MemoryRouter><InventoryRotationPage /></MemoryRouter></AuthProvider>)

    const table = await screen.findByRole('table', { name: 'Rotación y reposición' })
    await waitFor(() => expect(table).toHaveTextContent('Aceite 20W50'))
    expect(table).toHaveTextContent('22 / 52')
    expect(table).toHaveTextContent('Reponer ≈ 42')
    expect(table).toHaveTextContent('Sin ventas')
    expect(table).not.toHaveTextContent('Valor')
    expect(screen.getByText(/Mínimo = demanda × 22 días \(15 de reposición \+ 7 de seguridad\)/)).toBeInTheDocument()
    expect(within(screen.getByLabelText('Resumen por estado')).getByRole('button', { name: /Bajo el mínimo/ })).toHaveTextContent('1')
  })

  it('al elegir un estado filtra en el servidor y con costos muestra el valor', async () => {
    costsVisible = true
    const u = userEvent.setup()
    render(<AuthProvider><MemoryRouter><InventoryRotationPage /></MemoryRouter></AuthProvider>)

    await waitFor(() => expect(screen.getByRole('table', { name: 'Rotación y reposición' })).toHaveTextContent('Valor'))
    await u.click(within(screen.getByLabelText('Resumen por estado')).getByRole('button', { name: /Sin rotación/ }))
    await waitFor(() => expect(urls.filter((x) => x.startsWith('/inventory/rotation')).at(-1)).toContain('status=no_rotation'))
  })
})
