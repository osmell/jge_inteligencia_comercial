import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import ReportOperatorsPage from './pages/ReportOperatorsPage'

const context = { companies: [{ id: 1, code: 'A', name: 'Alfa' }], branches: [], sellers: [], operators: [] }

const row = (id: number, name: string, over: Record<string, unknown> = {}) => ({
  operator: { id, name, company_id: 1, company: 'Alfa', is_active: true },
  rank: id,
  sales: { net_total: 1600, documents: 3, customers: 2, average_ticket: 533.33, discount_pct: 2.5, below_list_pct: 30, categories_per_customer: 1, top3_concentration_pct: 100, previous_net_total: 1000, growth_pct: 60, previous_documents: 1, documents_growth_pct: 200, share_pct: 76 },
  production: { lines: 4, lines_per_document: 1.33, active_days: 2, documents_per_active_day: 1.5, timed_documents_pct: 100 },
  control: { high_discount_lines: 1, high_discount_lines_pct: 20, zero_price_lines: 0, zero_price_lines_pct: 0, atypical_documents: 0, atypical_documents_pct: null, negative_documents: 2, direct_sales_pct: 35 },
  semaphores: { growth_pct: 'good', discount_pct: 'good', below_list_pct: 'bad', high_discount_lines_pct: 'bad', zero_price_lines_pct: 'good', atypical_documents_pct: 'none' },
  ...over,
})

const report = (over: Record<string, unknown> = {}) => ({
  period: { from: '2026-10-01', to: '2026-10-31', days: 31 },
  comparison: { mode: 'previous', from: '2026-08-31', to: '2026-09-30', days: 31, uneven: false },
  rows: [row(1, 'Omar'), row(2, 'Olga')],
  totals: { operators: 2, net_total: 2100, previous_net_total: 1000, growth_pct: 110, documents: 4, lines: 5, average_ticket: 525, high_discount_lines: 1, zero_price_lines: 0, atypical_documents: 0, negative_documents: 2, red_flags: 2 },
  reconciliation: { consolidated_net: 2100, rows_net: 2100, net_difference: 0, consolidated_documents: 4, rows_documents: 4, documents_difference: 0, matches: true },
  notes: ['no_operator_cancellations'],
  ...over,
})

const distribution = (over: Record<string, unknown> = {}) => ({
  operator: { id: 1, name: 'Omar' },
  sellers: [{ id: 1, name: 'Ana', net_total: 800, share_pct: 80, direct: false }, { id: 2, name: 'Omar', net_total: 200, share_pct: 20, direct: true }],
  sellers_count: 2,
  branches: [{ id: 5, name: 'Centro', net_total: 600, share_pct: 60 }],
  documents: 4,
  timed_documents: 3,
  by_hour: Array.from({ length: 24 }, (_, h) => (h === 9 ? 2 : h === 15 ? 1 : 0)),
  by_weekday: [2, 1, 0, 0, 0, 1, 0],
  daily: [],
  ...over,
})

let calls: string[]
let payload: unknown
let dist: unknown

beforeEach(() => {
  calls = []
  payload = report()
  dist = distribution()
  vi.stubGlobal('fetch', vi.fn(async (url: string) => {
    calls.push(url)
    if (url.includes('/reports/context')) return new Response(JSON.stringify({ data: context }), { status: 200 })
    if (url.includes('/distribution')) return new Response(JSON.stringify({ data: dist }), { status: 200 })
    if (url.includes('/documents')) return new Response(JSON.stringify({ data: { subject: { id: 1, name: 'Omar' }, rows: [{ id: 9, document: 'A-9', issued_on: '2026-10-05', customer_code: 'C1', customer_name: 'Cliente Uno', branch: 'Centro', operator: 'Omar', seller: 'Ana', lines: 2, net_total: 1000, tax_total: 100, grand_total: 1100 }], totals: { documents: 1, net_total: 1000, tax_total: 100, grand_total: 1100 }, page: 1, per_page: 50, last_page: 1 } }), { status: 200 })
    return new Response(JSON.stringify({ data: payload }), { status: 200 })
  }))
})
afterEach(() => vi.unstubAllGlobals())

const renderPage = () => render(<MemoryRouter><ReportOperatorsPage /></MemoryRouter>)

describe('reporte de operadores', () => {
  it('muestra producción y control de cada operador con el nivel escrito, no solo en color', async () => {
    renderPage()
    const omar = (await screen.findByRole('button', { name: /Ver el detalle de Omar/ })).closest('tr')!

    expect(within(omar).getByText('1600,00')).toBeInTheDocument()
    expect(within(omar).getByText('1,33 por documento')).toBeInTheDocument()
    expect(within(omar).getAllByText('Crítico').length).toBe(2)   // bajo precio normal y descuento alto
    expect(within(omar).getByText('Sin dato')).toBeInTheDocument() // atípicos sin base suficiente
    expect(within(omar).getByText('35,0 %')).toBeInTheDocument()   // venta directa
  })

  it('avisa que el sistema no conoce las anulaciones por operador', async () => {
    renderPage()

    expect(await screen.findByText(/no sabe qué operador anuló o corrigió/)).toBeInTheDocument()
  })

  it('confirma el contraste contra la venta consolidada y alerta cuando no coincide', async () => {
    renderPage()
    expect(await screen.findByText(/coincide con la suma de los operadores/)).toBeInTheDocument()
  })

  it('alerta con la diferencia cuando no coincide', async () => {
    payload = report({ reconciliation: { consolidated_net: 2200, rows_net: 2100, net_difference: 100, consolidated_documents: 5, rows_documents: 4, documents_difference: 1, matches: false } })
    renderPage()

    expect(await screen.findByText(/no coincide con la suma de los operadores/)).toBeInTheDocument()
  })

  it('abre el detalle: primero las facturas y luego a quién y cuándo factura', async () => {
    const user = userEvent.setup()
    renderPage()

    await user.click(await screen.findByRole('button', { name: /Ver el detalle de Omar/ }))
    expect(await screen.findByText('A-9')).toBeInTheDocument()
    expect(calls.some((c) => c.includes('/reports/operators/1/documents'))).toBe(true)

    await user.click(screen.getByRole('tab', { name: 'A quién y cuándo factura' }))
    expect(await screen.findByText('venta directa')).toBeInTheDocument()
    expect(screen.getByText('Centro')).toBeInTheDocument()
    expect(screen.getByText('Documentos por día de la semana')).toBeInTheDocument()
    expect(screen.getByText('Documentos por hora')).toBeInTheDocument()
    expect(screen.getByText(/solo viene en 3 de 4 documentos/)).toBeInTheDocument()
  })

  it('si los archivos no traen la hora lo dice en vez de mostrar una gráfica vacía', async () => {
    dist = distribution({ by_hour: null, timed_documents: 0 })
    const user = userEvent.setup()
    renderPage()
    await user.click(await screen.findByRole('button', { name: /Ver el detalle de Omar/ }))

    await user.click(screen.getByRole('tab', { name: 'A quién y cuándo factura' }))

    expect(await screen.findByText(/no traen la hora de cada documento/)).toBeInTheDocument()
    expect(screen.queryByText('Documentos por hora')).not.toBeInTheDocument()
  })
})
