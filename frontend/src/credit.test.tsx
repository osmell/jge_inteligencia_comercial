import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { AuthProvider } from './auth/AuthContext'
import CreditDetailPage from './pages/CreditDetailPage'
import CreditAgingPage from './pages/CreditAgingPage'
import CreditPage from './pages/CreditPage'
import CustomerCredit from './components/CustomerCredit'
import { allocateOldestFirst, bucketLabel, type CreditInvoiceDetail, type CreditInvoiceRow, type CreditList } from './lib/credit'

const row = (o: Partial<CreditInvoiceRow> = {}): CreditInvoiceRow => ({
  id: 1, company_id: 1, company: 'Compañía Alfa', document: 'FC-D-3088', issued_on: '2026-09-10', customer: { code: '3892', name: 'LYDIA LUDIC' }, seller: 'IRIS',
  total: '1035.00', balance: '785.00', paid: '250.00', age_days: 40, bucket: '31-60', sales_linked: true, ...o,
})

let calls: string[]
let posts: { path: string; body: Record<string, unknown> }[]
let permissions: string[]
let list: CreditList
let detail: CreditInvoiceDetail

beforeEach(() => {
  calls = []
  posts = []
  permissions = ['credit.view']
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
  vi.stubGlobal('fetch', vi.fn(async (url: string, init?: RequestInit) => {
    const path = url.replace('http://localhost:8000/api/v1', '')
    calls.push(path)
    if (init?.method === 'POST') {
      posts.push({ path, body: JSON.parse(String(init.body)) })
      return new Response(JSON.stringify({ data: {} }), { status: 201 })
    }
    let body: unknown = { data: [] }
    if (path === '/auth/me') body = { data: { id: 3, username: 'a', name: 'Ana', role: { slug: 'administrador', name: 'Administrador' }, permissions, companies: [], branches: [] } }
    else if (path.startsWith('/credit/aging')) body = { data: { buckets: ['current', '1-30', '121+'], totals: { current: '100.0000', '1-30': '0.0000', '121+': '700.0000', total: '800.0000' }, customers: [{ company_id: 1, company: 'Compañía Alfa', code: '3892', name: 'LYDIA LUDIC', buckets: { current: '100.0000', '1-30': '0.0000', '121+': '700.0000' }, total: '800.0000', invoices: 2, oldest_days: 172 }] }, meta: { customers_total: 1, truncated: false } }
    else if (path.startsWith('/credit/promises')) body = { data: [{ id: 9, company_id: 1, customer: { code: '3892', name: 'LYDIA LUDIC' }, made_on: '2026-10-20', promised_on: '2026-10-25', amount: '400.0000', paid: '150.0000', status: 'open', note: 'Pasa el viernes', days_overdue: 0, user: 'Ana', cancel_reason: null }, { id: 8, company_id: 1, customer: { code: '3892', name: 'LYDIA LUDIC' }, made_on: '2026-10-01', promised_on: '2026-10-05', amount: '100.0000', paid: '0.0000', status: 'broken', note: null, days_overdue: 15, user: 'Ana', cancel_reason: null }] }
    else if (path.startsWith('/credit/customer')) body = { data: { buckets: ['current', '121+'], totals: { current: '100.0000', '121+': '700.0000', total: '800.0000' }, open_invoices: 2, oldest_days: 172, returns: { count: 1, subtotal: '200.0000', tax_total: '30.0000', total: '230.0000' } } }
    else if (path.startsWith('/credit/open-invoices')) body = { data: [{ id: 1, document: 'FC-D-3088', issued_on: '2026-09-10', total: '1035.00', balance: '785.00' }, { id: 5, document: 'FC-D-3095', issued_on: '2026-10-01', total: '500.00', balance: '500.00' }] }
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

  it('reparte un importe de la factura más antigua a la más reciente sin pasar del saldo', () => {
    const open = [{ id: 1, document: 'A', issued_on: '', total: '', balance: '785.00' }, { id: 2, document: 'B', issued_on: '', total: '', balance: '500.00' }]
    expect(allocateOldestFirst(open, 8000000)).toEqual({ 1: '785', 2: '15' })
    expect(allocateOldestFirst(open, 1000000)).toEqual({ 1: '100' })
  })

  it('sin permisos de cobro no ofrece registrar ni anular', async () => {
    renderDetail()

    await screen.findByRole('heading', { name: 'Factura de crédito FC-D-3088' })
    expect(screen.queryByRole('button', { name: 'Registrar abono' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Registrar nota de crédito' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Anular' })).not.toBeInTheDocument()
  })

  it('registra un abono repartido entre facturas del cliente', async () => {
    permissions = ['credit.view', 'credit.payments']
    const u = userEvent.setup()
    renderDetail()

    await u.click(await screen.findByRole('button', { name: 'Registrar abono' }))
    const dialog = await screen.findByRole('dialog')
    const first = await within(dialog).findByLabelText('Importe a aplicar a FC-D-3088')
    expect(first).toHaveValue('785')
    await u.clear(first)
    await u.type(within(dialog).getByLabelText('Repartir importe'), '900')
    await u.click(within(dialog).getByRole('button', { name: 'Aplicar a las más antiguas' }))
    expect(within(dialog).getByLabelText('Importe a aplicar a FC-D-3095')).toHaveValue('115')
    await u.selectOptions(within(dialog).getByLabelText('Método'), 'transferencia')
    await u.type(within(dialog).getByLabelText('Referencia'), 'T-9')
    await u.click(within(dialog).getByRole('button', { name: 'Guardar' }))

    await waitFor(() => expect(posts).toHaveLength(1))
    expect(posts[0].path).toBe('/credit/payments')
    expect(posts[0].body).toMatchObject({ company_id: 1, code: '3892', method: 'transferencia', reference: 'T-9', allocations: [{ invoice_id: 1, amount: '785' }, { invoice_id: 5, amount: '115' }] })
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
  })

  it('la nota de crédito exige que lo aplicado sea igual a su total', async () => {
    permissions = ['credit.view', 'credit.notes']
    const u = userEvent.setup()
    renderDetail()

    await u.click(await screen.findByRole('button', { name: 'Registrar nota de crédito' }))
    const dialog = await screen.findByRole('dialog')
    await within(dialog).findByLabelText('Importe a aplicar a FC-D-3088')
    await u.type(within(dialog).getByLabelText('Serie'), 'NC')
    await u.type(within(dialog).getByLabelText('Documento'), '77')
    await u.type(within(dialog).getByLabelText('Subtotal'), '200')
    await u.type(within(dialog).getByLabelText('Impuesto'), '30')
    await u.type(within(dialog).getByLabelText('Total'), '230')
    expect(within(dialog).getByText(/Debe ser igual al total de la nota/)).toBeInTheDocument()

    await u.click(within(dialog).getByRole('button', { name: 'Aplicar a las más antiguas' }))
    expect(within(dialog).queryByText(/Debe ser igual al total de la nota/)).not.toBeInTheDocument()
    await u.click(within(dialog).getByRole('button', { name: 'Guardar' }))

    await waitFor(() => expect(posts).toHaveLength(1))
    expect(posts[0].path).toBe('/credit/credit-notes')
    expect(posts[0].body).toMatchObject({ series: 'NC', number: '77', subtotal: '200', tax_total: '30', total: '230', allocations: [{ invoice_id: 1, amount: '230' }] })
  })

  it('anula un abono con su motivo', async () => {
    permissions = ['credit.view', 'credit.payments']
    detail = { ...detail, movements: detail.movements.map((m) => (m.id === 2 ? { ...m, reversed: false } : m)) }
    const u = userEvent.setup()
    renderDetail()

    await u.click(await screen.findByRole('button', { name: 'Anular' }))
    const dialog = await screen.findByRole('dialog')
    await u.type(within(dialog).getByLabelText('Motivo de la anulación'), 'Error de captura')
    await u.click(within(dialog).getByRole('button', { name: 'Guardar' }))

    await waitFor(() => expect(posts).toEqual([{ path: '/credit/movements/2/reverse', body: { note: 'Error de captura' } }]))
  })

  it('la antigüedad de cartera muestra los tramos y el saldo por cliente', async () => {
    render(<AuthProvider><MemoryRouter><CreditAgingPage /></MemoryRouter></AuthProvider>)

    const table = await screen.findByRole('table', { name: 'Antigüedad por cliente' })
    expect(table).toHaveTextContent('LYDIA LUDIC')
    expect(table).toHaveTextContent('la más antigua, 172 días')
    expect(table).toHaveTextContent('Más de 120 días')
    expect(table).toHaveTextContent('700,00')
    expect(screen.getByLabelText('Saldo por tramo')).toHaveTextContent('800,00')
    expect(screen.getByRole('button', { name: 'Exportar CSV' })).toBeInTheDocument()
  })

  it('la ficha del cliente muestra el saldo por tramo y las devoluciones aparte de las ventas', async () => {
    render(<AuthProvider><MemoryRouter><CustomerCredit companyId="1" code="3892" name="LYDIA LUDIC" netTotal="5000.00" /></MemoryRouter></AuthProvider>)

    expect(await screen.findByText(/Saldo por cobrar/)).toHaveTextContent('800,00')
    expect(screen.getByText(/Devoluciones \(notas de crédito vigentes\)/)).toHaveTextContent('200,00')
    expect(screen.getByText(/Venta neta menos devoluciones/)).toHaveTextContent('4800,00')
  })

  it('la ficha lista las promesas de pago y permite registrarlas con el permiso', async () => {
    permissions = ['credit.view', 'credit.promises']
    const u = userEvent.setup()
    render(<AuthProvider><MemoryRouter><CustomerCredit companyId="1" code="3892" name="LYDIA LUDIC" netTotal="5000.00" /></MemoryRouter></AuthProvider>)

    const list = await screen.findByRole('list', { name: 'Promesas de pago del cliente' })
    expect(list).toHaveTextContent('Pendiente')
    expect(list).toHaveTextContent('Abonado 150,00')
    expect(list).toHaveTextContent('Incumplida')

    await u.click(await screen.findByRole('button', { name: 'Registrar promesa' }))
    const dialog = await screen.findByRole('dialog')
    await u.type(within(dialog).getByLabelText('Monto prometido'), '300')
    await u.click(within(dialog).getByRole('button', { name: 'Guardar' }))

    await waitFor(() => expect(posts).toHaveLength(1))
    expect(posts[0].path).toBe('/credit/promises')
    expect(posts[0].body).toMatchObject({ company_id: 1, code: '3892', amount: '300', note: null })
  })

  it('sin el permiso de promesas no ofrece registrarlas ni cancelarlas', async () => {
    render(<AuthProvider><MemoryRouter><CustomerCredit companyId="1" code="3892" name="LYDIA LUDIC" netTotal="5000.00" /></MemoryRouter></AuthProvider>)

    await screen.findByRole('list', { name: 'Promesas de pago del cliente' })
    expect(screen.queryByRole('button', { name: 'Registrar promesa' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Cancelar promesa/ })).not.toBeInTheDocument()
  })
})
