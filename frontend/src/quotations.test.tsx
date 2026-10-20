import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { AuthProvider } from './auth/AuthContext'
import QuotationDetailPage from './pages/QuotationDetailPage'
import QuotationsPage from './pages/QuotationsPage'
import type { QuotationDetail, QuotationList, QuotationRow } from './lib/quotations'

const row = (o: Partial<QuotationRow> = {}): QuotationRow => ({
  id: 1, number: 'COT-000001', external_reference: 'PRES-77', status: 'issued', effective_status: 'issued', amount: '1500.50', valid_until: '2026-11-20', issued_at: '2026-10-19T10:00:00-06:00',
  customer: { company_id: 1, code: 'C1', name: 'Taller Pérez', do_not_contact: false }, seller: 'Vera', user: { id: 3, name: 'Vera' }, has_attachment: false, document: null, loss_reason: null, ...o,
})

const detail = (o: Partial<QuotationDetail> = {}): QuotationDetail => ({
  ...row(), description: '4 llantas', loss_note: null, converted_at: null, attachment: null, transitions: ['sent', 'following', 'accepted', 'rejected', 'cancelled'],
  events: [{ from: null, to: 'draft', note: null, at: '2026-10-19T09:00:00-06:00', user: 'Vera' }, { from: 'draft', to: 'issued', note: null, at: '2026-10-19T10:00:00-06:00', user: 'Vera' }], ...o,
})

let permissions: string[]
let calls: { path: string; method: string; body?: unknown }[]
let current: QuotationDetail
let list: QuotationList

beforeEach(() => {
  permissions = ['customers.view', 'interactions.manage']
  calls = []
  current = detail()
  list = {
    data: [row(), row({ id: 2, number: 'COT-000002', status: 'accepted', effective_status: 'accepted', external_reference: null, amount: '300.00' })],
    meta: { page: 1, has_more: false, counts: { issued: { count: 1, amount: 1500.5 }, accepted: { count: 1, amount: 300 } } },
  }
  document.cookie = 'XSRF-TOKEN=x'
  vi.stubGlobal('fetch', vi.fn(async (url: string, init?: RequestInit) => {
    const path = url.replace('http://localhost:8000/api/v1', '')
    const body = init?.body instanceof FormData ? init.body : init?.body ? JSON.parse(init.body as string) : undefined
    calls.push({ path, method: init?.method ?? 'GET', body })
    let out: unknown = { data: [] }
    if (path === '/auth/me') out = { data: { id: 3, username: 'v', name: 'Vera', role: { slug: 'vendedor', name: 'Vendedor' }, permissions, companies: [], branches: [] } }
    else if (path.startsWith('/quotations/1/documents')) out = { data: [{ id: 50, document: 'A-1001', issued_on: '2026-10-25', net_total: '1400.00' }] }
    else if (path.startsWith('/quotations/1')) out = { data: current }
    else if (path.startsWith('/quotations')) out = init?.method === 'POST' ? { data: detail({ id: 9 }) } : list
    else if (path.startsWith('/customers?q=')) out = { data: [{ company_id: 1, code: 'C7', name: 'Frenos Norte' }] }
    else if (path.startsWith('/companies')) out = { data: [{ id: 1, code: 'A', name: 'Compañía Alfa', is_active: true }] }
    return new Response(JSON.stringify(out), { status: 200 })
  }))
})
afterEach(() => vi.unstubAllGlobals())

const renderList = (search = '') =>
  render(<AuthProvider><MemoryRouter initialEntries={[`/cotizaciones${search}`]}><Routes><Route path="/cotizaciones" element={<QuotationsPage />} /><Route path="/cotizaciones/:id" element={<p>detalle abierto</p>} /></Routes></MemoryRouter></AuthProvider>)

const renderDetail = () =>
  render(<AuthProvider><MemoryRouter initialEntries={['/cotizaciones/1']}><Routes><Route path="/cotizaciones/:id" element={<QuotationDetailPage />} /></Routes></MemoryRouter></AuthProvider>)

describe('listado de cotizaciones', () => {
  it('muestra las cotizaciones con su estado en texto y los conteos por estado', async () => {
    renderList()

    const table = await screen.findByRole('table', { name: 'Cotizaciones' })
    expect(within(table).getByRole('link', { name: 'COT-000001' })).toHaveAttribute('href', '/cotizaciones/1')
    expect(table).toHaveTextContent('Ref. PRES-77')
    expect(table).toHaveTextContent('Emitida')
    expect(table).toHaveTextContent('Aceptada')
    expect(screen.getByRole('button', { name: 'Todas (2)' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Aceptada (1)' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Vencida (0)' })).toBeInTheDocument()
  })

  it('filtra por estado y avisa que aceptada no es venta', async () => {
    const u = userEvent.setup()
    renderList()

    await u.click(await screen.findByRole('button', { name: 'Aceptada (1)' }))
    await waitFor(() => expect(calls.some((c) => c.path.includes('status=accepted'))).toBe(true))
    expect(screen.getByText(/Una cotización aceptada todavía no es una venta/)).toBeInTheDocument()
  })

  it('sin permiso de seguimiento no ofrece crear', async () => {
    permissions = ['customers.view']
    renderList()

    await screen.findByRole('table', { name: 'Cotizaciones' })
    expect(screen.queryByRole('button', { name: 'Nueva cotización' })).not.toBeInTheDocument()
  })

  it('crea una cotización para el cliente de la ficha y abre su detalle', async () => {
    const u = userEvent.setup()
    renderList('?nueva=1&codigo=C1&compania=1&cliente=Taller%20P%C3%A9rez')

    const dialog = await screen.findByRole('dialog', { name: 'Nueva cotización' })
    expect(dialog).toHaveTextContent('Taller Pérez')
    await u.type(within(dialog).getByLabelText('Importe'), '1500,50')
    fireEvent.change(within(dialog).getByLabelText('Válida hasta'), { target: { value: '2026-11-30' } })
    await u.type(within(dialog).getByLabelText('Descripción (qué se cotiza)'), '4 llantas')
    await u.click(within(dialog).getByRole('checkbox', { name: /Emitirla de una vez/ }))
    await u.click(within(dialog).getByRole('button', { name: 'Crear cotización' }))

    expect(await screen.findByText('detalle abierto')).toBeInTheDocument()
    const post = calls.find((c) => c.method === 'POST' && c.path === '/quotations')
    expect(post?.body).toMatchObject({ company_id: 1, code: 'C1', status: 'issued', amount: '1500.50', valid_until: '2026-11-30', description: '4 llantas' })
  })

  it('busca al cliente cuando no viene de la ficha', async () => {
    const u = userEvent.setup()
    renderList()

    await u.click(await screen.findByRole('button', { name: 'Nueva cotización' }))
    const dialog = await screen.findByRole('dialog', { name: 'Nueva cotización' })
    expect(within(dialog).getByRole('button', { name: 'Crear cotización' })).toBeDisabled()
    await u.type(within(dialog).getByLabelText('Buscar cliente'), 'fre')
    expect(await within(dialog).findByRole('option', { name: 'Frenos Norte (C7)' })).toBeInTheDocument()
    await u.selectOptions(within(dialog).getByLabelText('Cliente'), '1|C7')
    expect(within(dialog).getByRole('button', { name: 'Crear cotización' })).toBeEnabled()
  })
})

describe('detalle de cotización', () => {
  it('muestra los datos, el historial y los botones del siguiente estado', async () => {
    renderDetail()

    expect(await screen.findByRole('heading', { name: 'Cotización COT-000001' })).toBeInTheDocument()
    expect(screen.getByText('4 llantas')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Taller Pérez' })).toHaveAttribute('href', '/clientes/ficha?codigo=C1&compania=1')
    expect(screen.getByRole('table', { name: 'Historial de la cotización' })).toHaveTextContent('Borrador → Emitida')
    expect(screen.getByRole('button', { name: 'Marcar como enviada' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Marcar como aceptada' })).toBeInTheDocument()
  })

  it('cambia de estado', async () => {
    const u = userEvent.setup()
    renderDetail()

    await u.click(await screen.findByRole('button', { name: 'Marcar como aceptada' }))
    await waitFor(() => expect(calls.some((c) => c.path === '/quotations/1/status' && c.method === 'PUT')).toBe(true))
    expect(calls.find((c) => c.path === '/quotations/1/status')?.body).toEqual({ status: 'accepted' })
  })

  it('rechazar pide el motivo de pérdida', async () => {
    const u = userEvent.setup()
    renderDetail()

    await u.click(await screen.findByRole('button', { name: 'Rechazar (perdida)' }))
    await u.selectOptions(screen.getByLabelText('Motivo (obligatorio)'), 'price')
    await u.type(screen.getByLabelText('Detalle (opcional)'), 'Encontró más barato')
    await u.click(screen.getByRole('button', { name: 'Marcar como perdida' }))

    await waitFor(() => expect(calls.some((c) => c.path === '/quotations/1/status')).toBe(true))
    expect(calls.find((c) => c.path === '/quotations/1/status')?.body).toEqual({ status: 'rejected', loss_reason: 'price', loss_note: 'Encontró más barato' })
  })

  it('vincula una factura del cliente', async () => {
    current = detail({ status: 'accepted', effective_status: 'accepted', transitions: ['rejected', 'cancelled'] })
    const u = userEvent.setup()
    renderDetail()

    expect(await screen.findByText(/Aceptada no es lo mismo que vendida/)).toBeInTheDocument()
    await u.click(screen.getByRole('button', { name: 'Vincular factura' }))
    await u.selectOptions(await screen.findByLabelText('Factura del cliente'), '50')
    await u.click(screen.getByRole('button', { name: 'Vincular' }))

    await waitFor(() => expect(calls.some((c) => c.path === '/quotations/1/link' && c.method === 'PUT')).toBe(true))
    expect(calls.find((c) => c.path === '/quotations/1/link')?.body).toEqual({ document_id: 50 })
  })

  it('una cotización convertida no se edita y solo quien gestiona clientes quita el vínculo', async () => {
    current = detail({ status: 'converted', effective_status: 'converted', transitions: [], document: { id: 50, number: 'A-1001', issued_on: '2026-10-25' } })
    renderDetail()

    expect(await screen.findByText('A-1001 (25 oct 2026)')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Editar' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Quitar vínculo con la factura' })).not.toBeInTheDocument()
  })

  it('sube un adjunto y rechaza uno que supera 15 MB sin enviarlo', async () => {
    const u = userEvent.setup()
    renderDetail()

    await screen.findByRole('heading', { name: 'Cotización COT-000001' })
    const input = document.getElementById('quotation-file') as HTMLInputElement
    const big = new File(['x'], 'grande.pdf', { type: 'application/pdf' })
    Object.defineProperty(big, 'size', { value: 16 * 1024 * 1024 })
    await u.upload(input, big)
    expect(await screen.findByText(/el límite es de 15 MB/)).toBeInTheDocument()
    expect(calls.some((c) => c.path.endsWith('/attachment'))).toBe(false)

    await u.upload(input, new File(['%PDF'], 'cot.pdf', { type: 'application/pdf' }))
    await waitFor(() => expect(calls.some((c) => c.path === '/quotations/1/attachment' && c.method === 'POST')).toBe(true))
    const sent = calls.find((c) => c.path.endsWith('/attachment'))
    const form = sent?.body
    expect(form).toBeInstanceOf(FormData)
    if (form instanceof FormData) expect(form.get('file')).toBeInstanceOf(File)
  })

  it('sin permiso de seguimiento solo consulta', async () => {
    permissions = ['customers.view']
    renderDetail()

    await screen.findByRole('heading', { name: 'Cotización COT-000001' })
    expect(screen.queryByRole('button', { name: 'Marcar como enviada' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Editar' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Subir adjunto' })).not.toBeInTheDocument()
  })
})
