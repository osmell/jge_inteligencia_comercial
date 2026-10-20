import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { AuthProvider } from './auth/AuthContext'
import CreditDetailPage from './pages/CreditDetailPage'
import CreditPage from './pages/CreditPage'
import { bucketLabel, type CreditInvoiceDetail, type CreditInvoiceRow, type CreditList } from './lib/credit'

const row = (o: Partial<CreditInvoiceRow> = {}): CreditInvoiceRow => ({
  id: 1, company_id: 1, company: 'Compañía Alfa', document: 'FC-D-3088', issued_on: '2026-09-10', customer: { code: '3892', name: 'LYDIA LUDIC' }, seller: 'IRIS',
  total: '1035.00', balance: '785.00', paid: '250.00', age_days: 40, bucket: '31-60', sales_linked: true, ...o,
})

let calls: string[]
let list: CreditList
let detail: CreditInvoiceDetail

beforeEach(() => {
  calls = []
  document.cookie = 'XSRF-TOKEN=x'
  list = {
    data: [row(), row({ id: 2, document: 'FC-D-3089', balance: '100.00', bucket: 'current', age_days: 0, issued_on: '2026-10-20', customer: { code: '1', name: 'Taller Pérez' } }), row({ id: 3, document: 'FC-D-3090', balance: '0.00', bucket: null, paid: '1035.00' })],
    meta: { page: 1, has_more: false, open_count: 2, open_balance: '885.00', buckets: ['current', '1-30', '31-60', '61-90', '91-120', '121+'] },
  }
  detail = {
    ...row(), branch: 'Central', subtotal: '1000.00', discount_total: '100.00', tax_total: '135.00', sales_linked: false,
    movements: [
      { id: 1, type: 'invoice', amount: '1035.00', occurred_on: '2026-09-10', method: null, reference: null, note: null, user: 'Ana', reverses_id: null, reversed: false },
      { id: 2, type: 'payment', amount: '-250.00', occurred_on: '2026-09-20', method: 'transferencia', reference: 'T-1', note: null, user: 'Ana', reverses_id: null, reversed: true },
      { id: 3, type: 'reversal', amount: '250.00', occurred_on: '2026-09-21', method: null, reference: null, note: 'Error de captura', user: 'Ana', reverses_id: 2, reversed: false },
    ],
  }
  vi.stubGlobal('fetch', vi.fn(async (url: string) => {
    const path = url.replace('http://localhost:8000/api/v1', '')
    calls.push(path)
    let body: unknown = { data: [] }
    if (path === '/auth/me') body = { data: { id: 3, username: 'a', name: 'Ana', role: { slug: 'administrador', name: 'Administrador' }, permissions: ['credit.view'], companies: [], branches: [] } }
    else if (path.startsWith('/credit/invoices/1')) body = { data: detail }
    else if (path.startsWith('/credit/invoices')) body = list
    else if (path.startsWith('/companies')) body = { data: [{ id: 1, code: 'A', name: 'Compañía Alfa', is_active: true }] }
    return new Response(JSON.stringify(body), { status: 200 })
  }))
})
afterEach(() => vi.unstubAllGlobals())

const renderList = () => render(<AuthProvider><MemoryRouter><CreditPage /></MemoryRouter></AuthProvider>)
const renderDetail = () => render(<AuthProvider><MemoryRouter initialEntries={['/credito/1']}><Routes><Route path="/credito/:id" element={<CreditDetailPage />} /></Routes></MemoryRouter></AuthProvider>)

describe('crédito y cobranzas', () => {
  it('etiqueta los tramos de antigüedad', () => {
    expect(['current', '1-30', '31-60', '121+', null].map(bucketLabel)).toEqual(['Al día', '1 a 30 días', '31 a 60 días', 'Más de 120 días', '—'])
  })

  it('lista las facturas con saldo, antigüedad en texto y el saldo por cobrar', async () => {
    renderList()

    const table = await screen.findByRole('table', { name: 'Facturas de crédito' })
    expect(within(table).getByRole('link', { name: 'FC-D-3088' })).toHaveAttribute('href', '/credito/1')
    expect(table).toHaveTextContent('785,00')
    expect(table).toHaveTextContent('31 a 60 días')
    expect(table).toHaveTextContent('40 días desde la factura')
    expect(table).toHaveTextContent('Al día')
    expect(table).toHaveTextContent('Pagada')
    expect(screen.getByText('Saldo por cobrar').closest('div')).toHaveTextContent('885,00')
    expect(screen.getByText('Facturas con saldo').closest('div')).toHaveTextContent('2')
  })

  it('envía los filtros al servidor', async () => {
    const u = userEvent.setup()
    renderList()

    await screen.findByRole('table', { name: 'Facturas de crédito' })
    await u.selectOptions(screen.getByLabelText('Compañía'), '1')
    await u.selectOptions(screen.getByLabelText('Estado'), 'all')
    await u.selectOptions(await screen.findByRole('combobox', { name: 'Antigüedad' }), '121+')
    await u.type(screen.getByLabelText('Buscar por documento o cliente'), 'lydia')

    await waitFor(() => {
      const last = calls.filter((c) => c.startsWith('/credit/invoices')).at(-1) ?? ''
      expect(last).toContain('company_id=1')
      expect(last).toContain('status=all')
      expect(last).toContain('bucket=121%2B')
      expect(last).toContain('q=lydia')
    })
  })

  it('sin facturas lo dice con claridad', async () => {
    list = { ...list, data: [], meta: { ...list.meta, open_count: 0, open_balance: '0.00' } }
    renderList()

    expect(await screen.findByText('No hay facturas de crédito con estos filtros.')).toBeInTheDocument()
  })

  it('el detalle muestra importes, saldo y los movimientos con lo anulado', async () => {
    renderDetail()

    expect(await screen.findByRole('heading', { name: 'Factura de crédito FC-D-3088' })).toBeInTheDocument()
    expect(screen.getByText('Saldo').closest('div')).toHaveTextContent('785,00')
    expect(screen.getByText(/todavía no está enlazada con una factura de venta/)).toBeInTheDocument()
    const table = screen.getByRole('table', { name: 'Movimientos de la factura' })
    expect(table).toHaveTextContent('Factura')
    expect(table).toHaveTextContent('Abono')
    expect(table).toHaveTextContent('transferencia · T-1')
    expect(table).toHaveTextContent('Anulado')
    expect(table).toHaveTextContent('Error de captura')
    expect(screen.getByText(/un error se corrige con una anulación/)).toBeInTheDocument()
  })
})
