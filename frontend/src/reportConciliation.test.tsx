import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { Conciliation } from './lib/reports'
import ReportConciliationPage from './pages/ReportConciliationPage'

const context = {
  companies: [{ id: 1, code: 'A', name: 'Alfa' }],
  branches: [{ id: 5, company_id: 1, code: 'C', name: 'Centro' }],
  sellers: [{ id: 7, company_id: 1, branch_id: null, name: 'Ana', is_active: true }],
  operators: [{ id: 8, company_id: 1, name: 'Omar', is_active: true }],
}

const clean = () => ({
  period: { from: '2026-10-01', to: '2026-10-31', days: 31 },
  consolidated: { net_total: 1500, tax_total: 150, grand_total: 1650, documents: 2, lines: 3 },
  breakdowns: ['company', 'branch', 'seller', 'operator'].map((dimension) => ({ dimension, rows: 1, net_total: 1500, documents: 2, net_difference: 0, documents_difference: 0, matches: true })),
  integrity: ['lines_count_mismatch', 'documents_without_lines', 'line_company_mismatch', 'line_branch_mismatch', 'line_date_mismatch', 'line_total_mismatch'].map((code) => ({ code, count: 0, ok: true })),
  imports: { batches: [] as Conciliation['imports']['batches'], matched: 0, differing: 0, not_comparable: 0, out_of_scope: 0 },
  credit: { linked: 0, mismatched: 0, unlinked: 0 },
  origin: [],
  problems: 0,
})

let payload: unknown
let calls: string[]

beforeEach(() => {
  calls = []
  payload = clean()
  vi.stubGlobal('fetch', vi.fn(async (url: string) => {
    calls.push(url)
    if (url.includes('/reports/context')) return new Response(JSON.stringify({ data: context }), { status: 200 })
    if (url.includes('/conciliation/issues')) return new Response(JSON.stringify({ data: { rows: [{ id: 3, document: 'A-3', issued_on: '2026-10-05', customer_name: 'Cliente Tres', branch: 'Centro', detail: 'El documento declara 5 líneas y tiene 2.' }], total: 1, page: 1, per_page: 50, last_page: 1 } }), { status: 200 })
    if (url.includes('/documents')) return new Response(JSON.stringify({ data: { subject: { id: 7, name: 'Ana' }, rows: [{ id: 9, document: 'A-9', issued_on: '2026-10-05', customer_code: 'C1', customer_name: 'Cliente Uno', branch: 'Centro', operator: 'Omar', seller: 'Ana', lines: 2, net_total: 1000, tax_total: 100, grand_total: 1100 }], totals: { documents: 1, net_total: 1000, tax_total: 100, grand_total: 1100 }, page: 1, per_page: 50, last_page: 1 } }), { status: 200 })
    return new Response(JSON.stringify({ data: payload }), { status: 200 })
  }))
})
afterEach(() => vi.unstubAllGlobals())

const renderPage = () => render(<MemoryRouter><ReportConciliationPage /></MemoryRouter>)

describe('conciliación', () => {
  it('cuando todo cuadra lo dice y no ofrece abrir ningún problema', async () => {
    renderPage()

    expect(await screen.findByText(/Todo cuadra/)).toBeInTheDocument()
    expect(screen.getByRole('table', { name: 'Sumas por dimensión contra el consolidado' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Ver documentos' })).not.toBeInTheDocument()
    expect(screen.getByText(/No hay importaciones de ventas que toquen el periodo/)).toBeInTheDocument()
  })

  it('no ofrece comparar con otro periodo: aquí no aplica', async () => {
    renderPage()
    await screen.findByText(/Todo cuadra/)

    expect(screen.queryByLabelText('Comparar con')).not.toBeInTheDocument()
  })

  it('señala una diferencia de integridad y abre los documentos con la descripción del problema', async () => {
    const data = clean()
    data.integrity[0] = { code: 'lines_count_mismatch', count: 1, ok: false }
    data.problems = 1
    payload = data
    const user = userEvent.setup()
    renderPage()

    expect(await screen.findByText(/Hay 1 concepto con diferencias/)).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Ver documentos' }))

    expect(await screen.findByText('El documento declara 5 líneas y tiene 2.')).toBeInTheDocument()
    expect(calls.some((c) => c.includes('/reports/conciliation/issues') && c.includes('code=lines_count_mismatch'))).toBe(true)
  })

  it('muestra una suma que no cuadra con su diferencia, con texto además de color', async () => {
    const data = clean()
    data.breakdowns[2] = { dimension: 'seller', rows: 1, net_total: 1400, documents: 2, net_difference: 100, documents_difference: 0, matches: false }
    data.problems = 1
    payload = data
    renderPage()

    const table = await screen.findByRole('table', { name: 'Sumas por dimensión contra el consolidado' })
    const sellers = within(table).getByText('Vendedores').closest('tr')!
    expect(within(sellers).getByText('Diferencia')).toBeInTheDocument()
    expect(within(sellers).getByText('100.00')).toBeInTheDocument()
  })

  it('una importación con venta no comparable lo explica y una que no cuadra se marca', async () => {
    const data = clean()
    data.imports = {
      batches: [
        { id: 1, name: 'ventas-octubre.xlsx', finished_at: null, date_from: '2026-10-01', date_to: '2026-10-31', declared_documents: 2, registered_documents: 2, declared_net: 1500, registered_net: 1500, net_comparable: false, duplicates: 1, matches: true },
        { id: 2, name: 'ventas-perdidas.xlsx', finished_at: null, date_from: '2026-10-01', date_to: '2026-10-31', declared_documents: 3, registered_documents: 2, declared_net: 1800, registered_net: 1500, net_comparable: true, duplicates: 0, matches: false },
      ],
      matched: 1, differing: 1, not_comparable: 1, out_of_scope: 2,
    }
    data.problems = 1
    payload = data
    renderPage()

    expect(await screen.findByText('no comparable')).toBeInTheDocument()
    const lost = screen.getByText('ventas-perdidas.xlsx').closest('tr')!
    expect(within(lost).getByText('Diferencia')).toBeInTheDocument()
    expect(screen.getByText(/2 importación\(es\) incluyen compañías fuera de tu alcance/)).toBeInTheDocument()
  })

  it('las facturas de crédito con diferencias se pueden abrir', async () => {
    const data = clean()
    data.credit = { linked: 3, mismatched: 2, unlinked: 1 }
    data.problems = 1
    payload = data
    const user = userEvent.setup()
    renderPage()

    expect(await screen.findByText('2 con diferencias')).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Ver facturas' }))
    expect(calls.some((c) => c.includes('code=credit_invoice_mismatch'))).toBe(true)
  })

  it('el detalle de facturación se abre por vendedor, operador o sucursal', async () => {
    const user = userEvent.setup()
    renderPage()
    await screen.findByText(/Todo cuadra/)

    expect(screen.queryByRole('table', { name: 'Documentos facturados' })).not.toBeInTheDocument()
    await user.selectOptions(screen.getByLabelText('Vendedor'), '7')

    expect(await screen.findByText('A-9')).toBeInTheDocument()
    expect(calls.some((c) => c.includes('/reports/sellers/7/documents'))).toBe(true)

    await user.selectOptions(screen.getByLabelText('Ver por'), 'operator')
    await user.selectOptions(screen.getByLabelText('Operador'), '8')
    await screen.findByRole('table', { name: 'Documentos facturados' })
    expect(calls.some((c) => c.includes('/reports/operators/8/documents'))).toBe(true)
  })
})
