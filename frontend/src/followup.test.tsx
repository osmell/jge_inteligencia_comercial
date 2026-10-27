import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { AuthProvider } from './auth/AuthContext'
import FollowUpKpisPage from './pages/FollowUpKpisPage'
import type { FollowUpKpis } from './lib/customers'

let kpis: FollowUpKpis
let urls: string[]

beforeEach(() => {
  urls = []
  document.cookie = 'XSRF-TOKEN=x'
  kpis = {
    period: { from: '2026-10-01', to: '2026-10-31' },
    contacts: { total: 12, outbound: 9, inbound: 3, customers: 7, effective: 8, effective_pct: 66.7, by_channel: { call: 8, whatsapp: 4 }, by_outcome: { spoke: 8, no_answer: 4 } },
    actions: { on_time: 5, late: 2, open_overdue: 3, upcoming: 4, due: 10, on_time_pct: 50, completed_pct: 70 },
    customers: { new: 6, recovered: 2, inactive_days: 30 },
    quotations: {
      issued: 10, issued_amount: '10000.00', converted: 3, converted_amount: '4000.00', accepted_without_invoice: 1, rejected: 2, cancelled: 1, expired: 1, open: 2,
      conversion_pct: 30, amount_conversion_pct: 40, avg_days_to_convert: 6.5, loss_reasons: { price: 2 },
    },
    by_user: [{ user: { id: 3, name: 'Vera' }, contacts: 12, customers: 7, effective: 8, actions_due: 10, actions_completed: 7, actions_open_overdue: 3, compliance_pct: 70, quotations_issued: 10, quotations_converted: 3, conversion_pct: 30 }],
  }
  vi.stubGlobal('fetch', vi.fn(async (url: string) => {
    const path = url.replace('http://localhost:8000/api/v1', '')
    urls.push(path)
    let body: unknown = { data: [] }
    if (path === '/auth/me') body = { data: { id: 3, username: 'v', name: 'Vera', role: { slug: 'supervisor_ventas', name: 'Supervisor' }, permissions: ['customers.view', 'interactions.manage'], companies: [], branches: [] } }
    else if (path.startsWith('/customers/follow-up-kpis')) body = { data: kpis }
    else if (path.startsWith('/companies')) body = { data: [{ id: 1, code: 'A', name: 'Compañía Alfa', is_active: true }] }
    else if (path.startsWith('/customers/sellers')) body = { data: [{ id: 7, name: 'Vera', company_id: 1, is_active: true }] }
    return new Response(JSON.stringify(body), { status: 200 })
  }))
})
afterEach(() => vi.unstubAllGlobals())

const renderPage = () => render(<AuthProvider><MemoryRouter><FollowUpKpisPage /></MemoryRouter></AuthProvider>)
const card = (label: string) => screen.getByText(label, { selector: 'p' }).closest('div') as HTMLElement

describe('indicadores de seguimiento', () => {
  it('muestra contactos, cumplimiento, cartera y conversión del periodo', async () => {
    renderPage()

    expect(await screen.findByText('12', { selector: 'p' })).toBeInTheDocument()
    expect(card('Contactos efectivos')).toHaveTextContent('66.7 %')
    expect(screen.getByRole('list', { name: 'Contactos por canal' })).toHaveTextContent('Llamada: 8')
    expect(card('Cumplidas a tiempo')).toHaveTextContent('50.0 %')
    expect(card('Cumplidas a tiempo')).toHaveTextContent('5 a tiempo · 2 tarde')
    expect(card('Acciones vencidas')).toHaveTextContent('10')
    expect(card('Clientes nuevos')).toHaveTextContent('6')
    expect(card('Clientes recuperados')).toHaveTextContent('2')
    expect(screen.getByText(/no se atribuye ninguna venta a un contacto/)).toBeInTheDocument()
    expect(card('Convertidas en venta')).toHaveTextContent('30.0 % de las emitidas')
    expect(card('Conversión por importe')).toHaveTextContent('Tardan 6.5 días en promedio')
    expect(card('Abiertas')).toHaveTextContent('2 vigentes · 1 aceptadas sin factura')
    expect(screen.getByRole('list', { name: 'Motivos de pérdida' })).toHaveTextContent('Precio: 2')
    expect(screen.getByText(/las abiertas todavía pueden convertirse/)).toBeInTheDocument()
    expect(within(screen.getByRole('table', { name: 'Seguimiento por usuario' })).getByText('Vera')).toBeInTheDocument()
  })

  it('envía el periodo y los filtros al servidor', async () => {
    const u = userEvent.setup()
    renderPage()

    await screen.findByRole('table', { name: 'Seguimiento por usuario' })
    expect(screen.getByLabelText('Vendedor')).toBeDisabled()
    await u.selectOptions(screen.getByLabelText('Compañía'), '1')
    await u.selectOptions(await screen.findByLabelText('Vendedor'), '7')
    fireEvent.change(screen.getByLabelText('Desde'), { target: { value: '2026-09-01' } })
    fireEvent.change(screen.getByLabelText('Hasta'), { target: { value: '2026-09-30' } })

    await waitFor(() => {
      const last = urls.filter((x) => x.startsWith('/customers/follow-up-kpis')).at(-1) ?? ''
      expect(last).toContain('from=2026-09-01')
      expect(last).toContain('to=2026-09-30')
      expect(last).toContain('company_id=1')
      expect(last).toContain('seller_id=7')
    })
  })

  it('un periodo invertido avisa y no consulta', async () => {
    renderPage()

    await screen.findByRole('table', { name: 'Seguimiento por usuario' })
    const before = urls.filter((x) => x.startsWith('/customers/follow-up-kpis')).length
    fireEvent.change(screen.getByLabelText('Desde'), { target: { value: '2999-01-01' } })

    expect(await screen.findByText(/la fecha final no puede ser anterior a la inicial/)).toBeInTheDocument()
    expect(urls.filter((x) => x.startsWith('/customers/follow-up-kpis')).length).toBe(before)
  })

  it('sin actividad lo dice con claridad', async () => {
    kpis = { ...kpis, by_user: [], contacts: { ...kpis.contacts, total: 0, by_channel: {}, effective_pct: null }, quotations: { ...kpis.quotations, loss_reasons: {} } }
    renderPage()

    expect(await screen.findByText('No hay actividad de seguimiento en este periodo.')).toBeInTheDocument()
    expect(screen.getByText('Sin contactos.')).toBeInTheDocument()
    expect(screen.getByText('Sin cotizaciones rechazadas.')).toBeInTheDocument()
  })
})
