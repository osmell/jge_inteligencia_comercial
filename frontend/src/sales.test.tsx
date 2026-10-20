import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import SalesDashboardPage from './pages/SalesDashboardPage'
import { daysBetween, presetRange, yearAgoRange } from './lib/sales'

const kpis = (o: Record<string, unknown> = {}) => ({
  net_total: '1000.00', tax_total: '120.00', grand_total: '1120.00', discount_total: '50.00', discount_pct: 4.76, units: '40.00', documents: 10, customers: 6,
  average_ticket: '100.00', estimated_margin: '300.00', estimated_margin_pct: 30, cost_coverage_pct: 100, ...o,
})

const summary = (o: Record<string, unknown> = {}) => ({
  data: {
    period: { from: '2026-10-01', to: '2026-10-19', days: 19 },
    previous_period: { from: '2026-09-12', to: '2026-09-30', days: 19 },
    current: kpis(), previous: kpis({ net_total: '800.00' }),
    change_pct: { net_total: 25, tax_total: 25, grand_total: 25, discount_total: null, units: 0, documents: -10, customers: null, average_ticket: 5, estimated_margin: 12.5 },
    first_sale_date: '2025-11-01', warnings: [], ...o,
  },
})

const row = (key: string, label: string, net: string) => ({ key, label, ...kpis({ net_total: net }) })

const rankingsBody = {
  data: {
    by_net_total: [row('P1', 'Filtro de aceite', '500.00'), row('P2', 'Pastillas de freno', '300.00')],
    by_units: [row('P2', 'Pastillas de freno', '300.00')],
    by_documents: [{ ...row('P1', 'Filtro de aceite', '500.00'), documents: 1 }],
    by_margin: [{ ...row('P2', 'Pastillas de freno', '300.00'), estimated_margin: '120.00', estimated_margin_pct: 40 }],
  },
  meta: { dimension: 'product', limit: 10 },
}

let urls: string[]
let respond: (path: string) => unknown

function mockApi() {
  urls = []
  vi.stubGlobal('fetch', vi.fn(async (url: string) => {
    const path = url.replace('http://localhost:8000/api/v1', '')
    urls.push(path)
    return new Response(JSON.stringify(respond(path)), { status: 200 })
  }))
}

const card = (label: string) => screen.getByText(label, { selector: 'p' }).closest('div')
const last = (needle: string) => urls.filter((u) => u.includes(needle)).at(-1) ?? ''

describe('dashboard de ventas', () => {
  beforeEach(() => {
    respond = (path) => {
      if (path.startsWith('/companies')) return { data: [{ id: 1, code: 'A', name: 'Compañía Alfa', is_active: true }, { id: 2, code: 'B', name: 'Compañía Beta', is_active: true }] }
      if (path.startsWith('/branches')) return { data: [{ id: 10, company_id: 1, code: 'C', name: 'Alfa Central', is_active: true }, { id: 20, company_id: 2, code: 'C', name: 'Beta Central', is_active: true }] }
      if (path.startsWith('/sales/summary')) return summary()
      if (path.startsWith('/sales/rankings')) return rankingsBody
      const dim = new URL(path, 'http://x').searchParams.get('dimension')
      if (dim === 'day') return { data: [{ key: '2026-10-01', ...kpis({ net_total: '400.00' }) }, { key: '2026-10-02', ...kpis({ net_total: '600.00' }) }], meta: { dimension: 'day', truncated: false } }
      if (dim === 'seller') return { data: [row('7', 'María López', '700.00'), row('8', 'Pedro Ruiz', '300.00')], meta: { dimension: 'seller', truncated: false } }
      return { data: [row('P1', 'Filtro de aceite', '500.00')], meta: { dimension: String(dim), truncated: true } }
    }
    mockApi()
  })
  afterEach(() => vi.unstubAllGlobals())

  it('muestra los KPI con su variación en texto y el desglose por vendedor', async () => {
    render(<SalesDashboardPage />)

    expect(await screen.findByText('1000,00', { selector: 'p' })).toBeInTheDocument()
    expect(card('Venta neta')).toHaveTextContent('+25,0 %')
    expect(card('Documentos')).toHaveTextContent('-10,0 %')
    expect(card('Descuento')).toHaveTextContent('50,00')
    expect(card('Descuento')).toHaveTextContent('Ya está restado de la venta neta')
    expect(card('Clientes compradores')).toHaveTextContent('Sin base de comparación')
    expect(screen.getByText(/Usa el costo actual, no el histórico/)).toBeInTheDocument()

    const table = await screen.findByRole('table', { name: 'Ventas por vendedor' })
    expect(within(table).getByRole('button', { name: 'María López' })).toBeInTheDocument()
    expect(within(table).getByText('700,00')).toBeInTheDocument()
  })

  it('avisa cuando el historial no cubre el periodo anterior', async () => {
    const base = respond
    respond = (path) => path.startsWith('/sales/summary') ? summary({ warnings: ['previous_period_incomplete'], first_sale_date: '2026-09-20' }) : base(path)
    render(<SalesDashboardPage />)

    expect(await screen.findByText(/empieza el 2026-09-20/)).toBeInTheDocument()
  })

  it('filtra todo el tablero al elegir una fila y permite quitar el filtro', async () => {
    render(<SalesDashboardPage />)
    await userEvent.click(await screen.findByRole('button', { name: 'María López' }))

    await waitFor(() => expect(last('/sales/summary')).toContain('seller_id=7'))
    expect(last('/sales/breakdown?')).toContain('seller_id=7')

    await userEvent.click(screen.getByRole('button', { name: 'Quitar filtro Vendedor: María López' }))
    await waitFor(() => expect(last('/sales/summary')).not.toContain('seller_id'))
  })

  it('ofrece grupo y línea del catálogo de productos como desgloses y los filtra', async () => {
    render(<SalesDashboardPage />)
    await userEvent.click(await screen.findByRole('tab', { name: 'Grupo (Nivel 1)' }))
    await screen.findByRole('table', { name: 'Ventas por grupo (nivel 1)' })
    expect(last('/sales/breakdown?')).toContain('dimension=group')
    expect(screen.getByText(/salen del catálogo de productos/)).toBeInTheDocument()

    await userEvent.click(screen.getByRole('tab', { name: 'Línea (Nivel 2)' }))
    await waitFor(() => expect(last('/sales/breakdown?')).toContain('dimension=line'))
  })

  it('cambia de dimensión y avisa del recorte y de las facturas repetidas entre grupos', async () => {
    render(<SalesDashboardPage />)
    await userEvent.click(await screen.findByRole('tab', { name: 'Producto' }))

    expect(within(await screen.findByRole('table', { name: 'Ventas por producto' })).getByText('Filtro de aceite')).toBeInTheDocument()
    expect(last('/sales/breakdown?')).toContain('dimension=product')
    expect(last('/sales/breakdown?')).toContain('limit=15')
    expect(screen.getByText(/Se muestran las 15 filas con más venta neta/)).toBeInTheDocument()
    expect(screen.getByText(/no sumes los documentos entre filas/)).toBeInTheDocument()
  })

  it('al elegir una compañía solo ofrece sus sucursales y reinicia la sucursal', async () => {
    render(<SalesDashboardPage />)
    await screen.findByRole('option', { name: 'Beta Central' })

    await userEvent.selectOptions(screen.getByLabelText('Compañía'), '2')
    expect(screen.queryByRole('option', { name: 'Alfa Central' })).not.toBeInTheDocument()
    await waitFor(() => expect(last('/sales/summary')).toContain('company_id=2'))

    await userEvent.selectOptions(screen.getByLabelText('Sucursal'), '20')
    await waitFor(() => expect(last('/sales/summary')).toContain('branch_id=20'))
    await userEvent.selectOptions(screen.getByLabelText('Compañía'), '1')
    await waitFor(() => expect(last('/sales/summary')).not.toContain('branch_id'))
  })

  it('no consulta con un rango personalizado inválido y explica el motivo', async () => {
    render(<SalesDashboardPage />)
    await screen.findByRole('table', { name: 'Ventas por vendedor' })
    await userEvent.selectOptions(screen.getByLabelText('Periodo'), 'custom')

    const from = screen.getByLabelText('Desde')
    const to = screen.getByLabelText('Hasta')
    await userEvent.clear(to)
    await userEvent.type(to, '2020-01-01')
    await userEvent.clear(from)
    await userEvent.type(from, '2026-01-01')

    expect(await screen.findByText('La fecha final no puede ser anterior a la inicial.')).toBeInTheDocument()
    expect(urls.filter((u) => u.includes('to=2020-01-01'))).toEqual([])
    expect(screen.queryByRole('table', { name: 'Ventas por vendedor' })?.textContent ?? '').not.toContain('María López')
  })

  it('muestra el error del servidor', async () => {
    respond = (path) => path.startsWith('/sales/') ? { message: 'No tienes permiso para realizar esta acción.' } : { data: [] }
    vi.stubGlobal('fetch', vi.fn(async (url: string) => new Response(JSON.stringify(respond(url.replace('http://localhost:8000/api/v1', ''))), { status: url.includes('/sales/') ? 403 : 200 })))
    render(<SalesDashboardPage />)

    expect((await screen.findAllByRole('alert'))[0]).toHaveTextContent('No tienes permiso para realizar esta acción.')
  })
})

describe('comparación de periodos', () => {
  const params = (needle: string) => new URL(last(needle), 'http://x').searchParams

  beforeEach(() => {
    respond = (path) => {
      if (path.startsWith('/companies') || path.startsWith('/branches')) return { data: [] }
      if (path.startsWith('/sales/summary')) return summary()
      if (path.startsWith('/sales/rankings')) return rankingsBody
      const dim = new URL(path, 'http://x').searchParams.get('dimension')
      if (dim === 'seller') {
        return {
          data: [
            { ...row('7', 'María López', '700.00'), previous_net_total: '400.00', net_change_pct: 75 },
            { ...row('8', 'Pedro Ruiz', '300.00'), previous_net_total: '0.00', net_change_pct: null },
          ],
          meta: { dimension: 'seller', truncated: false },
        }
      }
      return { data: [], meta: { dimension: String(dim), truncated: false } }
    }
    mockApi()
  })
  afterEach(() => vi.unstubAllGlobals())

  it('por defecto compara con el periodo anterior automático', async () => {
    render(<SalesDashboardPage />)

    expect(await screen.findByText(/Comparado con: 2026-09-12 a 2026-09-30/)).toBeInTheDocument()
    expect(last('/sales/summary')).not.toContain('compare_from')
    expect(card('Venta neta')).toHaveTextContent('vs. periodo anterior')
  })

  it('compara con el mismo periodo del año anterior', async () => {
    render(<SalesDashboardPage />)
    await screen.findByRole('table', { name: 'Ventas por vendedor' })
    await userEvent.selectOptions(screen.getByLabelText('Comparar con'), 'year')

    await waitFor(() => expect(last('/sales/summary')).toContain('compare_from'))
    const p = params('/sales/summary')
    expect(Number(p.get('compare_from')!.slice(0, 4))).toBe(Number(p.get('from')!.slice(0, 4)) - 1)
    expect(last('/sales/breakdown?')).toContain('compare_to')
    expect(card('Venta neta')).toHaveTextContent('vs. periodo comparado')
  })

  it('compara con otro periodo elegido y valida sus fechas antes de consultar', async () => {
    render(<SalesDashboardPage />)
    await screen.findByRole('table', { name: 'Ventas por vendedor' })
    await userEvent.selectOptions(screen.getByLabelText('Comparar con'), 'custom')

    expect(await screen.findByText('Indica la fecha inicial y la final de comparación.')).toBeInTheDocument()
    const before = urls.length
    await userEvent.type(screen.getByLabelText('Comparar desde'), '2026-09-10')
    await userEvent.type(screen.getByLabelText('Comparar hasta'), '2026-09-01')
    expect(await screen.findByText('La fecha final no puede ser anterior a la inicial de comparación.')).toBeInTheDocument()
    expect(urls.length).toBe(before)                                          // no se consultó con fechas inválidas

    await userEvent.clear(screen.getByLabelText('Comparar hasta'))
    await userEvent.type(screen.getByLabelText('Comparar hasta'), '2026-09-30')
    await waitFor(() => expect(last('/sales/summary')).toContain('compare_from=2026-09-10&compare_to=2026-09-30'))
  })

  it('avisa si los periodos son de distinta duración o se traslapan', async () => {
    const base = respond
    respond = (path) => path.startsWith('/sales/summary')
      ? summary({ comparison: 'custom', warnings: ['different_length', 'overlapping_periods'], previous_period: { from: '2026-10-05', to: '2026-10-09', days: 5 } })
      : base(path)
    render(<SalesDashboardPage />)

    expect(await screen.findByText(/distinta duración \(19 y 5 días\)/)).toBeInTheDocument()
    expect(screen.getByText(/se traslapan/)).toBeInTheDocument()
  })

  it('muestra la variación por fila y «Sin ventas antes» cuando no había base', async () => {
    render(<SalesDashboardPage />)
    const table = await screen.findByRole('table', { name: 'Ventas por vendedor' })

    expect(await within(table).findByText('+75,0 %')).toBeInTheDocument()
    expect(within(table).getByText('Sin ventas antes')).toBeInTheDocument()
  })

  it('las exportaciones llevan el periodo comparado', async () => {
    vi.stubGlobal('URL', { ...URL, createObjectURL: vi.fn(() => 'blob:x'), revokeObjectURL: vi.fn() })
    render(<SalesDashboardPage />)
    await screen.findByRole('table', { name: 'Ventas por vendedor' })
    await userEvent.selectOptions(screen.getByLabelText('Comparar con'), 'year')
    await userEvent.click(screen.getByRole('button', { name: 'Exportar a Excel' }))

    await waitFor(() => expect(last('/sales/export')).toContain('compare_from='))
  })

  it('calcula el mismo rango un año antes, incluido el 29 de febrero', () => {
    expect(yearAgoRange({ from: '2026-10-01', to: '2026-10-19' })).toEqual({ from: '2025-10-01', to: '2025-10-19' })
    expect(yearAgoRange({ from: '2024-02-01', to: '2024-02-29' })).toEqual({ from: '2023-02-01', to: '2023-02-28' })
  })
})

describe('rankings', () => {
  beforeEach(() => {
    respond = (path) => {
      if (path.startsWith('/companies') || path.startsWith('/branches')) return { data: [] }
      if (path.startsWith('/sales/summary')) return summary()
      if (path.startsWith('/sales/rankings')) return rankingsBody
      return { data: [], meta: { dimension: 'x', truncated: false } }
    }
    mockApi()
  })
  afterEach(() => vi.unstubAllGlobals())

  it('muestra los cuatro rankings con su métrica en texto', async () => {
    render(<SalesDashboardPage />)

    const byAmount = await screen.findByRole('list', { name: 'Por importe (venta neta)' })
    expect(within(byAmount).getAllByRole('listitem').map((li) => li.textContent)).toEqual([
      expect.stringContaining('Filtro de aceite'), expect.stringContaining('Pastillas de freno'),
    ])
    expect(within(byAmount).getByText('500,00')).toBeInTheDocument()
    expect(within(screen.getByRole('list', { name: 'Por frecuencia' })).getByText('1 factura')).toBeInTheDocument()
    expect(within(screen.getByRole('list', { name: 'Por margen estimado' })).getByText('120,00 (40,0 %)')).toBeInTheDocument()
    expect(screen.getByText(/Solo artículos con costo válido/)).toBeInTheDocument()
    expect(last('/sales/rankings')).toContain('dimension=product&limit=10')
  })

  it('cambia a categorías y recarga con los filtros del tablero', async () => {
    render(<SalesDashboardPage />)
    await screen.findByRole('list', { name: 'Por importe (venta neta)' })
    await userEvent.click(screen.getByRole('button', { name: 'Categorías' }))

    await waitFor(() => expect(last('/sales/rankings')).toContain('dimension=category'))
    expect(screen.getByRole('button', { name: 'Categorías' })).toHaveAttribute('aria-pressed', 'true')
  })

  it('elegir un artículo del ranking filtra todo el tablero', async () => {
    render(<SalesDashboardPage />)
    const byAmount = await screen.findByRole('list', { name: 'Por importe (venta neta)' })
    await userEvent.click(within(byAmount).getByRole('button', { name: 'Pastillas de freno' }))

    await waitFor(() => expect(last('/sales/summary')).toContain('product_code=P2'))
    expect(last('/sales/rankings')).toContain('product_code=P2')
    expect(screen.getByRole('button', { name: 'Quitar filtro Producto: Pastillas de freno' })).toBeInTheDocument()
  })

  it('no consulta rankings con un rango inválido', async () => {
    render(<SalesDashboardPage />)
    await screen.findByRole('list', { name: 'Por importe (venta neta)' })
    await userEvent.selectOptions(screen.getByLabelText('Periodo'), 'custom')
    await userEvent.clear(screen.getByLabelText('Hasta'))
    await userEvent.type(screen.getByLabelText('Hasta'), '2020-01-01')

    expect(urls.filter((u) => u.includes('to=2020-01-01'))).toEqual([])
    expect(screen.queryByRole('list', { name: 'Por importe (venta neta)' })).not.toBeInTheDocument()
  })
})

describe('exportación', () => {
  beforeEach(() => {
    respond = (path) => {
      if (path.startsWith('/companies') || path.startsWith('/branches')) return { data: [] }
      if (path.startsWith('/sales/summary')) return summary()
      return { data: [row('7', 'María López', '700.00')], meta: { dimension: 'seller', truncated: false } }
    }
    mockApi()
    vi.stubGlobal('URL', { ...URL, createObjectURL: vi.fn(() => 'blob:x'), revokeObjectURL: vi.fn() })
  })
  afterEach(() => vi.unstubAllGlobals())

  it('exporta a Excel con el periodo y los filtros del tablero', async () => {
    render(<SalesDashboardPage />)
    await userEvent.click(await screen.findByRole('button', { name: 'María López' }))
    await waitFor(() => expect(last('/sales/summary')).toContain('seller_id=7'))
    await userEvent.click(screen.getByRole('button', { name: 'Exportar a Excel' }))

    await waitFor(() => expect(last('/sales/export')).toContain('format=xlsx'))
    expect(last('/sales/export')).toContain('seller_id=7')
    expect(last('/sales/export')).toMatch(/from=\d{4}-\d{2}-\d{2}&to=/)
    expect(last('/sales/export')).not.toContain('dimension')
  })

  it('exporta en CSV el desglose de la pestaña activa', async () => {
    render(<SalesDashboardPage />)
    await userEvent.click(await screen.findByRole('tab', { name: 'Producto' }))
    await userEvent.click(screen.getByRole('button', { name: 'CSV por producto' }))

    await waitFor(() => expect(last('/sales/export')).toContain('format=csv&dimension=product'))
  })

  it('exporta el reporte en PDF con el periodo y la comparación vigentes', async () => {
    render(<SalesDashboardPage />)
    await screen.findByRole('table', { name: 'Ventas por vendedor' })
    await userEvent.click(screen.getByRole('button', { name: 'Exportar a PDF' }))

    await waitFor(() => expect(last('/sales/export')).toContain('format=pdf'))
  })

  it('muestra el error si la exportación falla y no se puede exportar con un rango inválido', async () => {
    render(<SalesDashboardPage />)
    await screen.findByRole('table', { name: 'Ventas por vendedor' })
    vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify({ message: 'No tienes permiso para realizar esta acción.' }), { status: 403 })))
    await userEvent.click(screen.getByRole('button', { name: 'Exportar a Excel' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('No tienes permiso para realizar esta acción.')

    await userEvent.selectOptions(screen.getByLabelText('Periodo'), 'custom')
    await userEvent.clear(screen.getByLabelText('Hasta'))
    await userEvent.type(screen.getByLabelText('Hasta'), '2020-01-01')
    expect(screen.getByRole('button', { name: 'Exportar a Excel' })).toBeDisabled()
    expect(screen.getByRole('button', { name: 'CSV por vendedor' })).toBeDisabled()
  })
})

describe('rangos de fechas', () => {
  const today = new Date(2026, 9, 19) // 19 oct 2026 (hora local)

  it('calcula los periodos predefinidos', () => {
    expect(presetRange('month', today)).toEqual({ from: '2026-10-01', to: '2026-10-19' })
    expect(presetRange('last-month', today)).toEqual({ from: '2026-09-01', to: '2026-09-30' })
    expect(presetRange('30d', today)).toEqual({ from: '2026-09-20', to: '2026-10-19' })
    expect(presetRange('ytd', today)).toEqual({ from: '2026-01-01', to: '2026-10-19' })
    expect(presetRange('last-month', new Date(2026, 0, 15))).toEqual({ from: '2025-12-01', to: '2025-12-31' })
  })

  it('cuenta los días del rango de forma inclusiva', () => {
    expect(daysBetween('2026-10-01', '2026-10-01')).toBe(1)
    expect(daysBetween('2026-09-20', '2026-10-19')).toBe(30)
  })
})
