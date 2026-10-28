import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import ReportProductsPage from './pages/ReportProductsPage'

const context = { companies: [{ id: 1, code: 'A', name: 'Alfa' }], branches: [], sellers: [], operators: [] }

const item = (rank: number, key: string, name: string, net: number, over: Record<string, unknown> = {}) => ({
  key, name, net_total: net, units: 10, documents: 3, customers: 2, rank, share_pct: 50, previous_net_total: 100, growth_pct: 25, ...over,
})

const person = (id: number, name: string, over: Record<string, unknown> = {}) => ({
  subject: { id, name, company_id: 1, is_active: true }, rank: id, net_total: 900, share_pct: 85.7, documents: 3, products_sold: 3,
  categories_sold: 3, categories_catalog: 4, category_coverage_pct: 75, lines_sold: 2, lines_catalog: 3, line_coverage_pct: 66.7, groups_sold: 1, top5_share_pct: 100, ...over,
})

const report = (over: Record<string, unknown> = {}) => ({
  period: { from: '2026-10-01', to: '2026-10-31', days: 31 },
  comparison: { mode: 'previous', from: '2026-08-31', to: '2026-09-30', days: 31, uneven: false },
  subject: 'seller', level: 'category',
  ranking: { items: [item(1, 'Taladros', 'Taladros', 700), item(2, 'Sierras', 'Sierras', 200, { growth_pct: null })], total_items: 2, truncated: false, net_total: 1050 },
  rows: [person(1, 'Ana'), person(2, 'Beto', { net_total: 150, category_coverage_pct: 25, categories_sold: 1 })],
  totals: { people: 2, net_total: 1050, catalog_categories: 4, catalog_lines: 3, average_category_coverage_pct: 50, below_half_coverage: 1 },
  reconciliation: { consolidated_net: 1050, rows_net: 1050, net_difference: 0, consolidated_documents: 4, rows_documents: 4, documents_difference: 0, matches: true },
  notes: ['catalog_coverage'],
  ...over,
})

const detail = {
  subject: { id: 2, name: 'Beto', company_id: 1, kind: 'seller' }, level: 'category', summary: person(2, 'Beto', { category_coverage_pct: 25, categories_sold: 1 }),
  ranking: { items: [item(1, 'Taladros', 'Taladros', 100, { team_share_pct: 66.67, mix_index: 100 }), item(2, 'Pintura', 'Pintura', 50, { team_share_pct: 4.76, mix_index: 700 })], total_items: 2, truncated: false, net_total: 150 },
  gaps: { categories: [{ key: 'Sierras', name: 'Sierras', net_total: 200, share_pct: 19.05, customers: 1 }], lines: [] },
  star_products: { top: 4, sold: 2, missing: [{ key: 'P2', name: 'Sierra', net_total: 200, units: 1, documents: 1, customers: 1 }] },
}

let calls: string[]

beforeEach(() => {
  calls = []
  vi.stubGlobal('fetch', vi.fn(async (url: string) => {
    calls.push(url)
    if (url.includes('/reports/context')) return new Response(JSON.stringify({ data: context }), { status: 200 })
    if (url.includes('/reports/products/')) return new Response(JSON.stringify({ data: detail }), { status: 200 })
    return new Response(JSON.stringify({ data: report() }), { status: 200 })
  }))
})
afterEach(() => vi.unstubAllGlobals())

const renderPage = () => render(<MemoryRouter><ReportProductsPage /></MemoryRouter>)

describe('mezcla de productos', () => {
  it('no muestra filas de vendedores bajo «Operadores» mientras llegan las nuevas', async () => {
    const user = userEvent.setup()
    let release: (r: Response) => void = () => {}
    renderPage()
    await screen.findByRole('button', { name: /Ver el detalle de Ana/ })
    vi.stubGlobal('fetch', vi.fn(async (url: string) => {
      if (url.includes('/reports/context')) return new Response(JSON.stringify({ data: context }), { status: 200 })
      return new Promise<Response>((resolve) => { release = resolve })
    }))

    await user.selectOptions(screen.getByLabelText('Evaluar a'), 'operator')

    expect(screen.queryByRole('button', { name: /Ver el detalle de Ana/ })).not.toBeInTheDocument()
    release(new Response(JSON.stringify({ data: report({ subject: 'operator', rows: [person(7, 'Omar')] }) }), { status: 200 }))
    expect(await screen.findByRole('button', { name: /Ver el detalle de Omar/ })).toBeInTheDocument()
  })

  it('lista lo más facturado y la cobertura de catálogo de cada persona', async () => {
    renderPage()
    const ana = (await screen.findByRole('button', { name: /Ver el detalle de Ana/ })).closest('tr')!
    const beto = screen.getByRole('button', { name: /Ver el detalle de Beto/ }).closest('tr')!

    expect(within(ana).getByText('3 de 4')).toBeInTheDocument()
    expect(within(ana).getByText('75.0 %')).toBeInTheDocument()
    expect(within(beto).getByText('25.0 %')).toBeInTheDocument()
    expect(screen.getByRole('table', { name: 'Lo más facturado por categoría' })).toHaveTextContent('Taladros')
    expect(screen.getByText(/«no vendió» no prueba que no lo haya ofrecido/)).toBeInTheDocument()
    expect(await screen.findByText(/coincide con la suma de los vendedores/)).toBeInTheDocument()
  })

  it('pide al servidor el nivel y a quién se evalúa', async () => {
    const user = userEvent.setup()
    renderPage()
    await screen.findByRole('button', { name: /Ver el detalle de Ana/ })

    await user.selectOptions(screen.getByLabelText('Ver la venta por'), 'product')
    await user.selectOptions(screen.getByLabelText('Evaluar a'), 'operator')

    expect(calls.some((c) => c.includes('/reports/products?') && c.includes('level=product') && c.includes('subject=operator'))).toBe(true)
  })

  it('abre el detalle con lo que la persona no vende y los productos estrella que le faltan', async () => {
    const user = userEvent.setup()
    renderPage()

    await user.click(await screen.findByRole('button', { name: /Ver el detalle de Beto/ }))
    expect(await screen.findByText('Categorías sin venta')).toBeInTheDocument()
    expect(screen.getByRole('table', { name: 'Categorías sin venta' })).toHaveTextContent('Sierras')
    expect(screen.getByText(/Vendió 2 de los 4 productos más vendidos/)).toBeInTheDocument()
    expect(screen.getByRole('table', { name: 'Productos estrella que no vendió' })).toHaveTextContent('Sierra')
    expect(screen.getByText(/vende más que el equipo/)).toBeInTheDocument()
    expect(calls.some((c) => c.includes('/reports/products/sellers/2') && c.includes('level=category'))).toBe(true)
  })

  it('abre en una ventana los clientes detrás de la cifra «Clientes»', async () => {
    const user = userEvent.setup()
    const customers = { level: 'category', item: { key: 'Taladros', name: 'Taladros' }, rows: [{ code: 'C1', name: 'Cliente C1', documents: 2, units: 5, net_total: 600, last_purchase: '2026-10-05' }, { code: 'C4', name: 'Cliente C4', documents: 1, units: 1, net_total: 100, last_purchase: '2026-10-07' }], totals: { customers: 2, net_total: 700 } }
    vi.stubGlobal('fetch', vi.fn(async (url: string) => {
      calls.push(url)
      if (url.includes('/reports/context')) return new Response(JSON.stringify({ data: context }), { status: 200 })
      if (url.includes('/reports/products/customers')) return new Response(JSON.stringify({ data: customers }), { status: 200 })
      if (url.includes('/reports/products/')) return new Response(JSON.stringify({ data: detail }), { status: 200 })
      return new Response(JSON.stringify({ data: report() }), { status: 200 })
    }))
    renderPage()

    await user.click(await screen.findByRole('button', { name: 'Ver los 2 clientes de Taladros' }))
    const dialog = await screen.findByRole('dialog', { name: 'Clientes de Taladros' })
    expect(await within(dialog).findByText('Cliente C4')).toBeInTheDocument()
    expect(within(dialog).getByText('600.00')).toBeInTheDocument()
    const request = calls.find((c) => c.includes('/reports/products/customers'))!
    expect(request).toContain('level=category')
    expect(request).toContain('key=Taladros')
    expect(request).not.toContain('seller_id')

    await user.click(within(dialog).getByRole('button', { name: 'Cerrar' }))
    expect(screen.queryByRole('dialog', { name: 'Clientes de Taladros' })).not.toBeInTheDocument()
  })

  it('en el detalle de una persona, los clientes son solo los suyos y Escape cierra solo la ventana de arriba', async () => {
    const user = userEvent.setup()
    vi.stubGlobal('fetch', vi.fn(async (url: string) => {
      calls.push(url)
      if (url.includes('/reports/context')) return new Response(JSON.stringify({ data: context }), { status: 200 })
      if (url.includes('/reports/products/customers')) return new Response(JSON.stringify({ data: { level: 'category', item: { key: 'Taladros', name: 'Taladros' }, rows: [], totals: { customers: 0, net_total: 0 } } }), { status: 200 })
      if (url.includes('/reports/products/')) return new Response(JSON.stringify({ data: detail }), { status: 200 })
      return new Response(JSON.stringify({ data: report() }), { status: 200 })
    }))
    renderPage()

    await user.click(await screen.findByRole('button', { name: /Ver el detalle de Beto/ }))
    const table = await screen.findByRole('table', { name: 'Lo que más factura por categoría' })
    await user.click(within(table).getAllByRole('button', { name: /Ver los 2 clientes de Taladros/ })[0])
    expect(await screen.findByRole('dialog', { name: 'Clientes de Taladros' })).toBeInTheDocument()
    expect(calls.some((c) => c.includes('/reports/products/customers') && c.includes('seller_id=2'))).toBe(true)

    await user.keyboard('{Escape}')
    expect(screen.queryByRole('dialog', { name: 'Clientes de Taladros' })).not.toBeInTheDocument()
    expect(screen.getByRole('dialog', { name: 'Mezcla de productos de Beto' })).toBeInTheDocument()
  })

  it('avisa cuando la lista de productos está recortada', async () => {
    vi.stubGlobal('fetch', vi.fn(async (url: string) => new Response(JSON.stringify({ data: url.includes('/reports/context') ? context : report({ ranking: { items: [item(1, 'P1', 'Taladro', 700)], total_items: 5000, truncated: true, net_total: 1050 } }) }), { status: 200 })))
    renderPage()

    expect(await screen.findByText(/Se muestran los 1 con más venta de 5000/)).toBeInTheDocument()
  })
})
