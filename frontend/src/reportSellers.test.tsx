import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { reportFiltersValid, reportQuery, initialReportFilters } from './lib/reports'
import ReportSellersPage from './pages/ReportSellersPage'

const context = { companies: [{ id: 1, code: 'A', name: 'Alfa' }], branches: [{ id: 5, company_id: 1, code: 'C', name: 'Centro' }], sellers: [], operators: [] }

const semaphores = (over: Record<string, string> = {}) => ({ goal_attainment: 'warn', growth_pct: 'good', discount_pct: 'good', below_list_pct: 'bad', top3_concentration_pct: 'good', lost_customers_pct: 'good', overdue_pct: 'good', quote_conversion_pct: 'none', overdue_follow_ups: 'none', ...over })

const row = (id: number, name: string, over: Record<string, unknown> = {}) => ({
  seller: { id, name, company_id: 1, company: 'Alfa', branch_id: 5, branch: 'Centro', is_active: true },
  rank: id,
  sales: { net_total: 1500, previous_net_total: 1000, growth_pct: 50, share_pct: 75, documents: 2, customers: 2, average_ticket: 750, discount_pct: 2.5, below_list_pct: 30, categories_per_customer: 1.5, top3_concentration_pct: 90 },
  goal: { goal: 2000, attainment_pct: 75, projected_pct: null, status: 'warn' },
  portfolio: { total: 4, active: 1, new: 0, recovered: 0, inactive: 1, lost: 1, lost_pct: 25 },
  credit: { balance: 1000, overdue: 400, overdue_pct: 40 },
  follow_up: { quotes_issued: 4, quotes_converted: 2, quote_conversion_pct: 50, quotes_amount: 400, has_user: id === 1, contacts: id === 1 ? 3 : null, actions_due: 3, actions_on_time_pct: 33.3, overdue_follow_ups: id === 1 ? 1 : null },
  semaphores: semaphores(),
  ...over,
})

const report = (over: Record<string, unknown> = {}) => ({
  period: { from: '2026-10-01', to: '2026-10-31', days: 31 },
  comparison: { mode: 'previous', from: '2026-08-31', to: '2026-09-30', days: 31, uneven: false },
  rows: [row(1, 'Ana'), row(2, 'Luis')],
  totals: { sellers: 2, net_total: 3000, previous_net_total: 2000, growth_pct: 50, documents: 4, average_ticket: 750, goal: 4000, attainment_pct: 75, sellers_with_goal: 2, credit_balance: 2000, credit_overdue: 800, red_flags: 2 },
  reconciliation: { consolidated_net: 3000, sellers_net: 3000, net_difference: 0, consolidated_documents: 4, sellers_documents: 4, documents_difference: 0, matches: true },
  notes: ['portfolio_and_credit_as_of_today'],
  ...over,
})

let calls: string[]
let payload: unknown

beforeEach(() => {
  calls = []
  payload = report()
  vi.stubGlobal('fetch', vi.fn(async (url: string) => {
    calls.push(url)
    if (url.includes('/reports/context')) return new Response(JSON.stringify({ data: context }), { status: 200 })
    if (url.includes('/documents')) return new Response(JSON.stringify({ data: { seller: { id: 1, name: 'Ana' }, rows: [{ id: 9, document: 'A-9', issued_on: '2026-10-05', customer_code: 'C1', customer_name: 'Cliente Uno', branch: 'Centro', operator: 'Omar', lines: 2, net_total: 1000, tax_total: 100, grand_total: 1100 }], totals: { documents: 1, net_total: 1000, tax_total: 100, grand_total: 1100 }, page: 1, per_page: 50, last_page: 1 } }), { status: 200 })
    return new Response(JSON.stringify({ data: payload }), { status: 200 })
  }))
})
afterEach(() => vi.unstubAllGlobals())

const renderPage = () => render(<MemoryRouter><ReportSellersPage /></MemoryRouter>)

describe('reporte de vendedores', () => {
  it('muestra a cada vendedor con sus cifras y el semáforo de cada indicador con texto, no solo color', async () => {
    renderPage()
    const ana = (await screen.findByRole('button', { name: /Ver la facturación de Ana/ })).closest('tr')!

    expect(within(ana).getByText('1500,00')).toBeInTheDocument()
    expect(within(ana).getAllByText('Atención').length).toBeGreaterThan(0)   // cumplimiento en amarillo
    expect(within(ana).getAllByText('Crítico').length).toBeGreaterThan(0)    // bajo precio normal en rojo
    expect(within(ana).getByText('meta 2000,00')).toBeInTheDocument()
    expect(screen.getByText('Indicadores críticos')).toBeInTheDocument()
  })

  it('un vendedor sin usuario vinculado se muestra «sin usuario», no ceros', async () => {
    renderPage()
    const luis = (await screen.findByRole('button', { name: /Ver la facturación de Luis/ })).closest('tr')!

    expect(within(luis).getByText('sin usuario')).toBeInTheDocument()
  })

  it('confirma el contraste cuando la venta consolidada coincide con la suma de los vendedores', async () => {
    renderPage()

    expect(await screen.findByText(/coincide con la suma de los vendedores/)).toBeInTheDocument()
  })

  it('alerta con la diferencia cuando no coincide', async () => {
    payload = report({ reconciliation: { consolidated_net: 3100, sellers_net: 3000, net_difference: 100, consolidated_documents: 5, sellers_documents: 4, documents_difference: 1, matches: false } })
    renderPage()

    expect(await screen.findByText(/no coincide con la suma de los vendedores/)).toBeInTheDocument()
  })

  it('explica las notas del servidor', async () => {
    payload = report({ notes: ['goal_not_comparable_with_branch_filter', 'uneven_comparison'] })
    renderPage()

    expect(await screen.findByText(/Con una sucursal elegida no se califica la meta/)).toBeInTheDocument()
    expect(screen.getByText(/dura distinto/)).toBeInTheDocument()
  })

  it('al cambiar el periodo vuelve a consultar con las fechas y la comparación elegidas', async () => {
    const user = userEvent.setup()
    renderPage()
    await screen.findByRole('button', { name: /Ver la facturación de Ana/ })

    await user.selectOptions(screen.getByLabelText('Comparar con'), 'last_year')

    await waitFor(() => expect(calls.some((c) => c.includes('/reports/sellers?') && c.includes('compare=last_year'))).toBe(true))
  })

  it('con un periodo inválido avisa y no consulta', async () => {
    const user = userEvent.setup()
    renderPage()
    await screen.findByRole('button', { name: /Ver la facturación de Ana/ })
    const before = calls.length

    const from = screen.getByLabelText('Desde')
    await user.clear(from)

    expect(await screen.findByText(/Indica un periodo válido/)).toBeInTheDocument()
    expect(calls.length).toBe(before)
  })

  it('abre el detalle de facturación del vendedor con sus documentos', async () => {
    const user = userEvent.setup()
    renderPage()

    await user.click(await screen.findByRole('button', { name: /Ver la facturación de Ana/ }))

    expect(await screen.findByText('A-9')).toBeInTheDocument()
    expect(screen.getByText('Cliente Uno')).toBeInTheDocument()
    expect(calls.some((c) => c.includes('/reports/sellers/1/documents'))).toBe(true)
  })
})

describe('filtros de reportes', () => {
  it('valida el periodo y la comparación personalizada', () => {
    const f = initialReportFilters()
    expect(reportFiltersValid(f)).toBe(true)
    expect(reportFiltersValid({ ...f, from: f.to, to: '2000-01-01' })).toBe(false)
    expect(reportFiltersValid({ ...f, compare: 'custom' })).toBe(false)
    expect(reportFiltersValid({ ...f, compare: 'custom', compare_from: '2026-01-01', compare_to: '2026-01-31' })).toBe(true)
  })

  it('arma la consulta solo con lo elegido', () => {
    const f = { ...initialReportFilters(), from: '2026-10-01', to: '2026-10-31', company_id: '3' }
    const q = new URLSearchParams(reportQuery(f))
    expect(q.get('company_id')).toBe('3')
    expect(q.has('branch_id')).toBe(false)
    expect(q.has('compare_from')).toBe(false)
  })
})
