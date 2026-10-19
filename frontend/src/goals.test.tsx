import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { AuthProvider } from './auth/AuthContext'
import GoalsPage from './pages/GoalsPage'
import type { GoalRow } from './lib/goals'

const user = (permissions: string[]) => ({
  id: 1, username: 'gg', name: 'Gerencia', role: { slug: 'gerente_general', name: 'Gerente General' }, permissions, companies: [], branches: [],
})

const goalRow = (o: Partial<GoalRow>): GoalRow => ({
  seller_id: 1, seller: 'Ana', company_id: 1, starts_on: null, ends_on: null, goal_amount: 1000, days_off: 0, source: 'manual',
  eligible_days: 31, days_in_month: 31, effective_goal: 1000, actual: 1250, compliance_pct: 125, status: 'met', expected_to_date: 1000,
  pace_pct: 125, projected: null, projected_pct: null, behind_pace: false, ...o,
})

const body = (rows: GoalRow[], state = 'closed') => ({
  data: rows,
  meta: {
    month: '2026-10', state, thresholds: { warning_pct: 70, goal_pct: 100, pace_alert_pct: 80 },
    totals: { effective_goal: 1500, actual: 1350, compliance_pct: 90, sellers: 2, with_goal: 2, met: 1, warning: 0, danger: 1, no_goal: 0, behind_pace: rows.filter((r) => r.behind_pace).length },
  },
})

const rows = [
  goalRow({}),
  goalRow({ seller_id: 2, seller: 'Beto', goal_amount: 500, effective_goal: 500, actual: 100, compliance_pct: 20, status: 'danger', behind_pace: true, pace_pct: 40, projected: 200, projected_pct: 40 }),
]

let calls: { path: string; method: string; body?: string }[]
let permissions: string[]

beforeEach(() => {
  calls = []
  permissions = ['sales.view', 'goals.manage']
  vi.stubGlobal('fetch', vi.fn(async (url: string, init?: RequestInit) => {
    const path = url.replace('http://localhost:8000/api/v1', '')
    calls.push({ path, method: init?.method ?? 'GET', body: init?.body as string | undefined })
    const json = (b: unknown) => new Response(JSON.stringify(b), { status: 200 })
    if (path === '/auth/me') return json({ data: user(permissions) })
    if (path.startsWith('/companies')) return json({ data: [{ id: 1, code: 'A', name: 'Compañía Alfa', is_active: true }] })
    if (path.startsWith('/sales/goals/suggestions')) return json({ data: [{ seller_id: 1, seller: 'Ana', suggested_amount: 1100, average: 1000, months_used: 3 }] })
    if (path.startsWith('/sales/goals') && init?.method === 'PUT') return json({ data: { changed: 1 } })
    if (path.startsWith('/sales/goals')) return json(body(rows))
    return json({ data: [] })
  }))
  document.cookie = 'XSRF-TOKEN=x'
})
afterEach(() => vi.unstubAllGlobals())

const renderPage = () => render(<AuthProvider><GoalsPage /></AuthProvider>)

describe('metas de ventas', () => {
  it('muestra cumplimiento, estado en texto y alerta de ritmo', async () => {
    renderPage()

    const table = await screen.findByRole('table', { name: 'Metas por vendedor' })
    expect(within(table).getByText('Meta cumplida')).toBeInTheDocument()
    expect(within(table).getByText('Por debajo')).toBeInTheDocument()
    expect(within(table).getByText(/atrasado/)).toBeInTheDocument()
    expect(screen.getByText(/Por debajo del ritmo esperado/)).toHaveTextContent('Beto')
    expect(screen.getByText('1 · 0 · 1')).toBeInTheDocument()
  })

  it('sin permiso de captura no ofrece editar', async () => {
    permissions = ['sales.view']
    renderPage()

    await screen.findByRole('table', { name: 'Metas por vendedor' })
    expect(screen.queryByRole('button', { name: 'Editar metas' })).not.toBeInTheDocument()
  })

  it('aplica sugerencias, permite ajustarlas y guarda marcando su origen', async () => {
    const u = userEvent.setup()
    renderPage()

    await u.click(await screen.findByRole('button', { name: 'Editar metas' }))
    await u.click(screen.getByRole('button', { name: 'Aplicar sugerencias' }))
    expect(await screen.findByLabelText('Meta de Ana')).toHaveValue(1100)
    expect(screen.getByLabelText('Meta de Beto')).toHaveValue(500)

    await u.clear(screen.getByLabelText('Meta de Beto'))
    await u.type(screen.getByLabelText('Meta de Beto'), '600')
    await u.type(screen.getByLabelText('Días de ausencia de Beto'), '2')
    await u.click(screen.getByRole('button', { name: 'Guardar metas' }))

    await waitFor(() => expect(calls.some((c) => c.method === 'PUT')).toBe(true))
    const sent = JSON.parse(calls.find((c) => c.method === 'PUT')!.body!)
    expect(sent.month).toMatch(/^\d{4}-\d{2}$/)
    expect(sent.goals).toEqual([
      { seller_id: 1, amount: 1100, days_off: 0, source: 'suggested', starts_on: null, ends_on: null },
      { seller_id: 2, amount: 600, days_off: 2, source: 'manual', starts_on: null, ends_on: null },
    ])
    expect(await screen.findByRole('button', { name: 'Editar metas' })).toBeInTheDocument()
  })

  it('exporta el mes y filtros consultados', async () => {
    const u = userEvent.setup()
    URL.createObjectURL = vi.fn(() => 'blob:x')
    URL.revokeObjectURL = vi.fn()
    renderPage()

    await u.click(await screen.findByRole('button', { name: 'Exportar a CSV' }))
    await waitFor(() => expect(calls.some((c) => c.path.startsWith('/sales/goals/export?month=') && c.path.endsWith('format=csv'))).toBe(true))

    await u.click(screen.getByRole('button', { name: 'Exportar a PDF' }))
    await waitFor(() => expect(calls.some((c) => c.path.endsWith('format=pdf'))).toBe(true))
  })

  it('un mes inválido no consulta', async () => {
    const u = userEvent.setup()
    renderPage()
    await screen.findByRole('table', { name: 'Metas por vendedor' })
    const before = calls.filter((c) => c.path.startsWith('/sales/goals')).length

    await u.clear(screen.getByLabelText('Mes'))
    expect(await screen.findByText('Indica un mes válido.')).toBeInTheDocument()
    expect(calls.filter((c) => c.path.startsWith('/sales/goals')).length).toBe(before)
  })
})
