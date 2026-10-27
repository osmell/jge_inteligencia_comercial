import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import ReportBranchesPage from './pages/ReportBranchesPage'

const context = { companies: [{ id: 1, code: 'A', name: 'Alfa' }], branches: [], sellers: [], operators: [] }

const row = (id: number, name: string, over: Record<string, unknown> = {}) => ({
  branch: { id, name, code: 'C', company_id: 1, company: 'Alfa', is_active: true },
  rank: id,
  sales: { net_total: 1500, documents: 2, customers: 2, average_ticket: 750, discount_pct: 2.5, below_list_pct: 10, categories_per_customer: 1, top3_concentration_pct: 90, previous_net_total: 1000, growth_pct: 50, previous_documents: 1, documents_growth_pct: 100, share_pct: 75 },
  dependency: { sellers_count: 2, top_seller: 'Ana', top_seller_pct: 60 },
  portfolio: { total: 3, active: 1, new: 0, recovered: 0, inactive: 0, lost: 2, lost_pct: 66.67 },
  credit: { balance: 1000, overdue: 400, overdue_pct: 40 },
  inventory: { products_in_stock: 2, no_rotation: 1, no_rotation_pct: 50, sold_products: 2, stockout_with_demand: 1, stockout_demand_pct: 50, open_shortfalls: 2 },
  semaphores: { growth_pct: 'good', discount_pct: 'good', below_list_pct: 'warn', top3_concentration_pct: 'bad', top_seller_dependency_pct: 'warn', lost_customers_pct: 'bad', overdue_pct: 'bad', no_rotation_pct: 'bad', stockout_demand_pct: 'bad', open_shortfalls: 'good' },
  ...over,
})

const report = (over: Record<string, unknown> = {}) => ({
  period: { from: '2026-10-01', to: '2026-10-31', days: 31 },
  comparison: { mode: 'previous', from: '2026-08-31', to: '2026-09-30', days: 31, uneven: false },
  rows: [row(1, 'Centro'), row(2, 'Norte', { dependency: { sellers_count: 0, top_seller: null, top_seller_pct: null }, semaphores: { top_seller_dependency_pct: 'none' } })],
  totals: { branches: 2, net_total: 3000, previous_net_total: 2000, growth_pct: 50, documents: 4, average_ticket: 750, credit_balance: 2000, credit_overdue: 800, no_rotation: 2, stockout_with_demand: 2, open_shortfalls: 4, red_flags: 6 },
  reconciliation: { consolidated_net: 3000, rows_net: 3000, net_difference: 0, consolidated_documents: 4, rows_documents: 4, documents_difference: 0, matches: true },
  notes: ['portfolio_credit_inventory_as_of_today'],
  ...over,
})

const mixData = (over: Record<string, unknown> = {}) => ({
  branches: [{ id: 1, name: 'Centro' }, { id: 2, name: 'Norte' }],
  categories: [{ name: 'Herramientas', net_total: 100, share_pct: 50, cells: [{ branch_id: 1, net_total: 80, share_pct: 80, index: 160 }, { branch_id: 2, net_total: 20, share_pct: 20, index: 40 }] }],
  shown: 1,
  total_categories: 1,
  ...over,
})

let calls: string[]
let payload: unknown
let mix: unknown

beforeEach(() => {
  calls = []
  payload = report()
  mix = mixData()
  vi.stubGlobal('fetch', vi.fn(async (url: string) => {
    calls.push(url)
    if (url.includes('/reports/context')) return new Response(JSON.stringify({ data: context }), { status: 200 })
    if (url.includes('/branches/mix')) return new Response(JSON.stringify({ data: mix }), { status: 200 })
    if (url.includes('/documents')) return new Response(JSON.stringify({ data: { subject: { id: 1, name: 'Centro' }, rows: [{ id: 9, document: 'A-9', issued_on: '2026-10-05', customer_code: 'C1', customer_name: 'Cliente Uno', branch: 'Centro', operator: 'Omar', seller: 'Ana', lines: 2, net_total: 1000, tax_total: 100, grand_total: 1100 }], totals: { documents: 1, net_total: 1000, tax_total: 100, grand_total: 1100 }, page: 1, per_page: 50, last_page: 1 } }), { status: 200 })
    return new Response(JSON.stringify({ data: payload }), { status: 200 })
  }))
})
afterEach(() => vi.unstubAllGlobals())

const renderPage = () => render(<MemoryRouter><ReportBranchesPage /></MemoryRouter>)

describe('reporte de sucursales', () => {
  it('muestra cada sucursal con su resultado, dependencia, cartera, cobranza y existencias, con el nivel escrito', async () => {
    renderPage()
    const centro = (await screen.findByRole('button', { name: /Ver la facturación de Centro/ })).closest('tr')!

    expect(within(centro).getByText('1,500.00')).toBeInTheDocument()
    expect(within(centro).getByText('Ana · 2 vend.')).toBeInTheDocument()
    expect(within(centro).getAllByText('Crítico').length).toBeGreaterThanOrEqual(5)
    expect(within(centro).getByText('2 · 66.7 %')).toBeInTheDocument()
  })

  it('una sucursal que solo vende de mostrador no muestra dependencia de nadie', async () => {
    renderPage()
    const norte = (await screen.findByRole('button', { name: /Ver la facturación de Norte/ })).closest('tr')!

    expect(within(norte).getByText('sin vendedores · 0 vend.')).toBeInTheDocument()
  })

  it('explica que cartera, cobranza y existencias son a hoy y sin costos', async () => {
    renderPage()

    expect(await screen.findByText(/a la fecha de hoy.*sin costos ni valuación/)).toBeInTheDocument()
  })

  it('confirma o alerta el contraste con la venta consolidada', async () => {
    renderPage()
    expect(await screen.findByText(/coincide con la suma de las sucursales/)).toBeInTheDocument()
  })

  it('alerta con la diferencia cuando no coincide', async () => {
    payload = report({ reconciliation: { consolidated_net: 3100, rows_net: 3000, net_difference: 100, consolidated_documents: 5, rows_documents: 4, documents_difference: 1, matches: false } })
    renderPage()
    expect(await screen.findByText(/no coincide con la suma de las sucursales/)).toBeInTheDocument()
  })

  it('muestra la mezcla de categorías con el índice y marca quién vende más y menos que el conjunto', async () => {
    renderPage()

    const table = await screen.findByRole('table', { name: 'Mezcla de categorías por sucursal' })
    const herramientas = within(table).getByText('Herramientas').closest('tr')!
    expect(within(herramientas).getByText('160')).toBeInTheDocument()
    expect(within(herramientas).getByText('vende más que el conjunto')).toBeInTheDocument()
    expect(within(herramientas).getByText('vende menos que el conjunto')).toBeInTheDocument()
  })

  it('avisa cuando solo se muestran las categorías con más venta', async () => {
    mix = mixData({ total_categories: 40, shown: 15 })
    renderPage()

    expect(await screen.findByText(/Se muestran las 15 categorías con más venta de 40/)).toBeInTheDocument()
  })

  it('abre la facturación de la sucursal', async () => {
    const user = userEvent.setup()
    renderPage()

    await user.click(await screen.findByRole('button', { name: /Ver la facturación de Centro/ }))

    expect(await screen.findByText('A-9')).toBeInTheDocument()
    expect(calls.some((c) => c.includes('/reports/branches/1/documents'))).toBe(true)
  })
})
