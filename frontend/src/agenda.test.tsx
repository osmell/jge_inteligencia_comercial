import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { AuthProvider } from './auth/AuthContext'
import AgendaPage from './pages/AgendaPage'
import type { Agenda } from './lib/customers'

const action = (id: number, name: string, text: string, overdue = 0) => ({
  id,
  customer: { company_id: 1, code: `C${id}`, name, phone: '5555-1111', whatsapp: null, seller: 'Vera' },
  next_action: text,
  next_action_at: '2026-10-20T09:00:00-06:00',
  days_overdue: overdue,
  origin: { channel: 'call' as const, outcome: 'interested' as const, occurred_at: '2026-10-15T10:00:00-06:00' },
  user: { id: 3, name: 'Vera' },
})

let agenda: Agenda
let calls: { path: string; method: string; body?: string }[]

beforeEach(() => {
  calls = []
  document.cookie = 'XSRF-TOKEN=x'
  agenda = {
    data: {
      overdue: [action(1, 'Taller Pérez', 'Enviar lista de precios', 2)],
      upcoming: [action(2, 'Repuestos Sol', 'Llamar para cotizar')],
      reactivate: [{ company_id: 1, company: 'Alfa', code: 'C9', name: 'Frenos Norte', phone: null, whatsapp: '5555-9999', status: 'inactive', last_purchase: '2026-09-01', days_since_last_purchase: 49, seller: 'Vera', last_contact_at: null }],
    },
    meta: { overdue_total: 1, upcoming_total: 1, reactivate_total: 1, horizon_days: 7, no_contact_days: 30, truncated: false },
  }
  vi.stubGlobal('fetch', vi.fn(async (url: string, init?: RequestInit) => {
    const path = url.replace('http://localhost:8000/api/v1', '')
    calls.push({ path, method: init?.method ?? 'GET', body: init?.body as string | undefined })
    let body: unknown = { data: [] }
    if (path === '/auth/me') body = { data: { id: 1, username: 'v', name: 'Vera', role: { slug: 'vendedor', name: 'Vendedor' }, permissions: ['customers.view', 'interactions.manage'], companies: [], branches: [] } }
    else if (path.startsWith('/customers/agenda')) body = agenda
    else if (path.startsWith('/companies')) body = { data: [{ id: 1, code: 'A', name: 'Compañía Alfa', is_active: true }] }
    else if (path.startsWith('/customers/sellers')) body = { data: [{ id: 7, name: 'Vera', company_id: 1, is_active: true }] }
    else if (path.endsWith('/complete')) body = { data: {} }
    return new Response(JSON.stringify(body), { status: 200 })
  }))
})
afterEach(() => vi.unstubAllGlobals())

const renderAgenda = () => render(<AuthProvider><MemoryRouter><AgendaPage /></MemoryRouter></AuthProvider>)

describe('agenda de seguimiento', () => {
  it('muestra vencidas, próximas y clientes por reactivar con su enlace a la ficha', async () => {
    renderAgenda()

    const overdue = await screen.findByRole('table', { name: 'Acciones vencidas' })
    expect(overdue).toHaveTextContent('Enviar lista de precios')
    expect(overdue).toHaveTextContent('Vencida hace 2 días')
    expect(within(overdue).getByRole('link', { name: 'Taller Pérez' })).toHaveAttribute('href', '/clientes/ficha?codigo=C1&compania=1')
    expect(screen.getByRole('table', { name: 'Acciones próximas' })).toHaveTextContent('Llamar para cotizar')

    const reactivate = screen.getByRole('table', { name: 'Clientes por reactivar' })
    expect(reactivate).toHaveTextContent('Frenos Norte')
    expect(reactivate).toHaveTextContent('Inactivo')
    expect(reactivate).toHaveTextContent('Nunca')
    expect(reactivate).toHaveTextContent('WhatsApp 5555-9999')
  })

  it('marca una acción como cumplida y vuelve a consultar', async () => {
    const u = userEvent.setup()
    renderAgenda()

    await u.click(await screen.findByRole('button', { name: /Marcar cumplida: Enviar lista de precios/ }))
    await waitFor(() => expect(calls.some((c) => c.path === '/customers/interactions/1/complete' && c.method === 'PUT')).toBe(true))
    expect(JSON.parse(calls.find((c) => c.path.endsWith('/complete'))?.body ?? '{}')).toEqual({ done: true })
    await waitFor(() => expect(calls.filter((c) => c.path.startsWith('/customers/agenda')).length).toBeGreaterThan(1))
  })

  it('los filtros se envían al servidor y el vendedor exige elegir compañía', async () => {
    const u = userEvent.setup()
    renderAgenda()

    await screen.findByRole('table', { name: 'Acciones vencidas' })
    expect(screen.getByLabelText('Vendedor')).toBeDisabled()
    await u.selectOptions(screen.getByLabelText('Compañía'), '1')
    await u.selectOptions(await screen.findByLabelText('Vendedor'), '7')
    await u.selectOptions(screen.getByLabelText('Plazo de las acciones'), '30')
    await u.selectOptions(screen.getByLabelText('Sin contacto desde hace'), '60')
    await u.click(screen.getByRole('checkbox', { name: 'Solo lo que yo registré' }))

    await waitFor(() => {
      const last = calls.filter((c) => c.path.startsWith('/customers/agenda')).at(-1)?.path ?? ''
      expect(last).toContain('company_id=1')
      expect(last).toContain('seller_id=7')
      expect(last).toContain('horizon_days=30')
      expect(last).toContain('no_contact_days=60')
      expect(last).toContain('mine=1')
    })
  })

  it('sin pendientes lo dice con claridad', async () => {
    agenda = { data: { overdue: [], upcoming: [], reactivate: [] }, meta: { ...agenda.meta, overdue_total: 0, upcoming_total: 0, reactivate_total: 0 } }
    renderAgenda()

    expect(await screen.findByText('No hay acciones vencidas.')).toBeInTheDocument()
    expect(screen.getByText('No hay acciones programadas en este plazo.')).toBeInTheDocument()
    expect(screen.getByText('No hay clientes por reactivar con estos filtros.')).toBeInTheDocument()
  })
})
