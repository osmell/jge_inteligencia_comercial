import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { AuthProvider } from './auth/AuthContext'
import { dailyPoints, formatChange } from './lib/dashboard'
import HomePage from './pages/HomePage'

let calls: string[]
let profile: string
let sections: string[]
let failing: string | null
let linked: number | null
let team: string[]

const sales = {
  period: { from: '2026-10-01', to: '2026-10-03', label: 'Mes en curso' },
  current: { net_total: '3500.00', documents: 3, customers: 2, average_ticket: '1166.67' },
  previous: { from: '2026-09-01', to: '2026-09-03', net_total: '2000.00', documents: 2, change_pct: 75 },
  year_ago: { from: '2025-10-01', to: '2025-10-03', net_total: '600.00', documents: 1, change_pct: 483.3 },
  daily: [{ date: '2026-10-01', net_total: '1000.00', documents: 1 }, { date: '2026-10-03', net_total: '2500.00', documents: 2 }],
  daily_previous: [{ date: '2026-09-02', net_total: '2000.00', documents: 2 }],
  monthly: [{ month: '2026-09', net_total: '2000.00', documents: 2 }, { month: '2026-10', net_total: '3500.00', documents: 3 }],
  history_since: '2025-10-08',
}

beforeEach(() => {
  document.cookie = 'XSRF-TOKEN=x'
  calls = []
  profile = 'company'
  sections = ['sales', 'goals', 'credit', 'quotations']
  failing = null
  linked = null
  team = []
  vi.stubGlobal('fetch', vi.fn(async (url: string) => {
    const path = url.replace('http://localhost:8000/api/v1', '')
    calls.push(path)
    let body: unknown = { data: {} }
    let status = 200
    if (path === '/auth/me') body = { data: { id: 1, username: 'a', name: 'Ana', role: { slug: 'administrador', name: 'Admin' }, permissions: [], companies: [], branches: [] } }
    else if (path === '/dashboard') body = { data: { profile, team, linked_sellers: linked, sections, periods: ['month', 'prev_month', 'quarter', 'year'], companies: [{ id: 1, name: 'Alfa' }, { id: 2, name: 'Beta' }], last_sale: '2026-10-20', last_import: '2026-10-21T12:00:00Z' } }
    else if (failing && path.startsWith(`/dashboard/${failing}`)) { status = 500; body = { message: 'Error interno.' } }
    else if (path.startsWith('/dashboard/sales')) body = { data: sales }
    else if (path.startsWith('/dashboard/goals')) body = { data: { month: '2026-10', state: 'current', totals: { goal: 7000, actual: 3500, remaining: 3500, compliance_pct: 50, projected: 5000, projected_pct: 71.4, days_left: 11, needed_per_day: 318.18, sellers_with_goal: 2, met: 0 }, rows: [{ seller_id: 1, seller: 'Vera', effective_goal: 3000, actual: 1500, compliance_pct: 50, projected: 2000, projected_pct: 66.7, status: 'danger', behind_pace: true }] } }
    else if (path.startsWith('/dashboard/credit')) body = { data: { total: '1500.0000', overdue: '1500.00', overdue_pct: 100, invoices: 2, customers: 2, buckets: [{ bucket: 'current', amount: '0.0000' }, { bucket: '31-60', amount: '1000.0000' }, { bucket: '121+', amount: '500.0000' }], top_debtors: [{ company_id: 1, code: 'CV', name: 'Cliente CV', total: '1000.0000', oldest_days: 40 }], promises: { due_next_7_days: 1, due_amount: '300.00', broken_30_days: 1, attention: [{ company_id: 1, code: 'CV', name: 'Cliente CV', promised_on: '2026-10-25', amount: '300.00', status: 'open', days_overdue: 0 }, { company_id: 1, code: 'CB', name: 'Cliente CB', promised_on: '2026-10-15', amount: '100.00', status: 'broken', days_overdue: 6 }] } } }
    else if (path.startsWith('/dashboard/followup')) body = { data: { overdue: 2, today: 1, date: '2026-10-21', actions: [{ customer: { company_id: 1, code: 'CV', name: 'Cliente CV' }, next_action: 'Llamar', next_action_at: '2026-10-19T10:00:00Z', days_overdue: 2 }, { customer: { company_id: 1, code: 'CB', name: 'Cliente CB' }, next_action: 'Visitar', next_action_at: '2026-10-21T15:00:00Z', days_overdue: 0 }], no_contact: { count: 3, days: 30, top: [{ code: 'CV', company_id: 1, name: 'Cliente CV', status: 'lost', days_since_last_purchase: 120, seller: 'Vera' }] } } }
    else if (path.startsWith('/dashboard/quotations')) body = { data: { open: { count: 2, amount: '600.00' }, expiring: { count: 1, amount: '300.00' }, expired_unclosed: 0, issued: { count: 2, amount: '600.00' }, converted: { count: 0, amount: '0.00' } } }
    return new Response(JSON.stringify(body), { status })
  }))
})
afterEach(() => vi.unstubAllGlobals())

const renderHome = () => render(<AuthProvider><MemoryRouter><HomePage /></MemoryRouter></AuthProvider>)

describe('ayudas del dashboard', () => {
  it('rellena con ceros los días sin venta para comparar el día n con el día n', () => {
    expect(dailyPoints([{ date: '2026-10-02', net_total: '50.00' }], '2026-10-01', '2026-10-03').map((p) => [p.key, p.value])).toEqual([['2026-10-01', 0], ['2026-10-02', 50], ['2026-10-03', 0]])
  })

  it('escribe el cambio con signo y dice cuando no hay base', () => {
    expect([formatChange(12.54), formatChange(-3), formatChange(0), formatChange(null)]).toEqual(['+12,5 %', '−3 %', '0 %', 'Sin base de comparación'])
  })
})

describe('dashboard de inicio', () => {
  it('muestra cada sección con sus cifras, comparativos y gráficos', async () => {
    renderHome()

    expect(await screen.findByText('Hola, Ana')).toBeInTheDocument()
    const sales_ = await screen.findByRole('region', { name: 'Ventas' })
    await waitFor(() => expect(sales_.querySelector('p.tabular')).toHaveTextContent('3500,00'))
    expect(within(sales_).getByText(/Contra el tramo anterior: \+75 %/)).toBeInTheDocument()
    expect(within(sales_).getByText(/Contra el año anterior: \+483,3 %/)).toBeInTheDocument()
    expect(within(sales_).getByRole('img', { name: /Venta neta diaria/ })).toBeInTheDocument()
    expect(within(sales_).getByRole('img', { name: 'Venta neta mensual, últimos 12 meses' })).toBeInTheDocument()

    const goals = await screen.findByRole('region', { name: 'Metas del mes' })
    expect(await within(goals).findByRole('progressbar', { name: 'Cumplimiento 2026-10' })).toHaveAttribute('aria-valuenow', '50')
    expect(within(goals).getByText(/Al ritmo actual cerraría en/)).toBeInTheDocument()
    expect(within(goals).getByRole('progressbar', { name: 'Vera' })).toBeInTheDocument()

    const credit = await screen.findByRole('region', { name: 'Cartera' })
    expect(await within(credit).findByRole('img', { name: 'Cartera por antigüedad' })).toBeInTheDocument()
    expect(within(credit).getByText('Saldo vencido')).toBeInTheDocument()

    const quotes = await screen.findByRole('region', { name: 'Cotizaciones' })
    expect(await within(quotes).findByText('Por vencer en 3 días')).toBeInTheDocument()
    expect(screen.getByText(/Ventas hasta el 20 oct 2026/)).toBeInTheDocument()
  })

  it('una sección que falla muestra su error con reintento sin afectar a las demás', async () => {
    failing = 'credit'
    const u = userEvent.setup()
    renderHome()

    const credit = await screen.findByRole('region', { name: 'Cartera' })
    expect(await within(credit).findByText(/No se pudo cargar esta sección/)).toBeInTheDocument()
    expect(await within(await screen.findByRole('region', { name: 'Cotizaciones' })).findByText('Abiertas')).toBeInTheDocument()

    failing = null
    await u.click(within(credit).getByRole('button', { name: 'Reintentar' }))
    expect(await within(credit).findByText('Saldo por cobrar')).toBeInTheDocument()
  })

  it('al cambiar el periodo y la compañía vuelve a pedir cada sección con esos filtros', async () => {
    const u = userEvent.setup()
    renderHome()
    await screen.findByRole('region', { name: 'Ventas' })

    await u.selectOptions(screen.getByLabelText('Periodo'), 'prev_month')
    await u.selectOptions(screen.getByLabelText('Compañía'), 'Beta')
    await waitFor(() => expect(calls).toContain('/dashboard/sales?period=prev_month&company_id=2'))
    expect(calls).toContain('/dashboard/credit?period=prev_month&company_id=2')
  })

  it('solo pide las secciones que la API le indica (por rol)', async () => {
    profile = 'seller'
    sections = ['sales', 'quotations']
    linked = 1
    renderHome()

    await screen.findByRole('region', { name: 'Tus cotizaciones' })
    expect(screen.queryByRole('region', { name: 'Cartera' })).not.toBeInTheDocument()
    expect(calls.some((c) => c.startsWith('/dashboard/credit'))).toBe(false)
    expect(screen.getByText(/Solo lo tuyo/)).toBeInTheDocument()
  })

  it('avisa al vendedor sin vendedor vinculado y a quien no tiene indicadores', async () => {
    profile = 'seller'
    sections = ['sales']
    linked = 0
    renderHome()
    expect(await screen.findByText(/todavía no está vinculado a un vendedor/)).toBeInTheDocument()
  })

  it('sin secciones lo explica y deja los accesos rápidos', async () => {
    sections = []
    renderHome()
    expect(await screen.findByText(/no tiene indicadores asignados/)).toBeInTheDocument()
  })

  it('el supervisor ve su equipo y los clientes por recuperar con enlace a la ficha', async () => {
    profile = 'team'
    sections = ['sales', 'followup']
    team = ['Vera', 'Beto']
    linked = 2
    renderHome()

    expect(await screen.findByText('Vera, Beto')).toBeInTheDocument()
    expect(screen.getByText(/Lo de los vendedores que supervisas/)).toBeInTheDocument()
    const follow = await screen.findByRole('region', { name: 'Seguimiento' })
    expect(await within(follow).findByText('Sin contacto en 30 días')).toBeInTheDocument()
    expect(within(within(follow).getByRole('list', { name: 'Clientes sin contacto reciente' })).getByRole('link', { name: 'Cliente CV' })).toHaveAttribute('href', '/clientes/ficha?codigo=CV&compania=1')
    expect(within(follow).getByText(/Perdido · 120 días sin comprar · Vera/)).toBeInTheDocument()
  })

  it('el supervisor sin vendedores asignados recibe un aviso', async () => {
    profile = 'team'
    sections = ['sales']
    linked = 0
    renderHome()

    expect(await screen.findByText(/Todavía no tienes vendedores asignados/)).toBeInTheDocument()
  })

  it('el vendedor ve su agenda primero, su meta con lo que necesita por día y sus promesas, sin ranking de otros', async () => {
    profile = 'seller'
    sections = ['sales', 'credit', 'goals', 'followup']
    linked = 1
    team = ['Vera']
    renderHome()

    const regions = await screen.findAllByRole('region')
    await waitFor(() => expect(screen.getByRole('region', { name: 'Tu agenda' })).toBeInTheDocument())
    expect(screen.getAllByRole('region').map((r) => r.getAttribute('aria-labelledby'))).toEqual(['dash-tu-agenda', 'dash-tu-meta-del-mes', 'dash-tus-ventas', 'dash-tus-clientes-por-cobrar'])
    expect(regions.length).toBe(4)

    const agenda = screen.getByRole('region', { name: 'Tu agenda' })
    const list = await within(agenda).findByRole('list', { name: 'Acciones pendientes' })
    expect(within(list).getByRole('link', { name: 'Cliente CV' })).toHaveAttribute('href', '/clientes/ficha?codigo=CV&compania=1')
    expect(within(list).getByText('Vencida hace 2 días')).toBeInTheDocument()
    expect(within(list).getByText('Para hoy')).toBeInTheDocument()
    expect(within(agenda).getByText('Lo que tienes pendiente')).toBeInTheDocument()

    const goals = screen.getByRole('region', { name: 'Tu meta del mes' })
    expect(await within(goals).findByRole('progressbar', { name: 'Tu avance 2026-10' })).toBeInTheDocument()
    expect(within(goals).getByText(/necesitas vender 318,18 por día en los 11 días que quedan/)).toBeInTheDocument()
    expect(within(goals).queryByRole('progressbar', { name: 'Vera' })).not.toBeInTheDocument()            // sin desglose por vendedor

    const credit = screen.getByRole('region', { name: 'Tus clientes por cobrar' })
    const promises = await within(credit).findByRole('list', { name: 'Promesas de pago por atender' })
    expect(within(promises).getByText(/incumplida hace 6 días/)).toBeInTheDocument()
    expect(within(promises).getByText(/para el 25 oct 2026/)).toBeInTheDocument()
  })
})
