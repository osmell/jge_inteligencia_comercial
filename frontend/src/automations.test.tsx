import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { AuthProvider } from './auth/AuthContext'
import AutomationsPage from './pages/AutomationsPage'

let masterEnabled: boolean
let calls: { path: string; method: string; body: Record<string, unknown> | undefined }[]

const catalog = () => ({
  data: [
    {
      key: 'insights_refresh', label: 'Recálculo de la inteligencia comercial', description: 'Recalcula cada noche los análisis.', frequency: 'daily', respect_window: false, available: true, enabled: true,
      params: [{ name: 'hour', label: 'Hora de ejecución', unit: 'hora (0 a 23)', help: 'Hora del día a la que corre.', min: 0, max: 23, default: 2, value: 2 }],
      last_run: { status: 'failed', trigger: 'schedule', started_at: '2026-10-21T08:00:00Z', finished_at: '2026-10-21T08:00:05Z', attempt: 1, error: 'El servicio no respondió', result: null },
    },
    { key: 'followup_due', label: 'Acciones de seguimiento del día y vencidas', description: 'Avisa de las acciones vencidas.', frequency: 'daily', respect_window: true, available: false, enabled: true, params: [], last_run: null },
  ],
  meta: { master_enabled: masterEnabled, window: { start: 7, end: 19 }, retry_minutes: [5, 15] },
})

beforeEach(() => {
  masterEnabled = true
  calls = []
  document.cookie = 'XSRF-TOKEN=x'
  vi.stubGlobal('fetch', vi.fn(async (url: string, init?: RequestInit) => {
    const path = url.replace('http://localhost:8000/api/v1', '')
    const method = init?.method ?? 'GET'
    calls.push({ path, method, body: init?.body ? JSON.parse(String(init.body)) : undefined })
    let body: unknown = { data: [] }
    if (path === '/auth/me') body = { data: { id: 1, username: 'a', name: 'Ana', role: { slug: 'administrador', name: 'Administrador' }, permissions: ['automations.manage'], companies: [], branches: [] } }
    else if (path.startsWith('/automations/runs')) {
      body = { data: [
        { id: 2, automation: 'insights_refresh', label: 'Recálculo de la inteligencia comercial', trigger: 'schedule', status: 'failed', attempt: 2, started_at: '2026-10-21T08:06:00Z', finished_at: null, next_retry_at: '2026-10-21T08:21:00Z', result: null, error: 'El servicio no respondió' },
        { id: 1, automation: 'insights_refresh', label: 'Recálculo de la inteligencia comercial', trigger: 'manual', status: 'ok', attempt: 1, started_at: '2026-10-20T08:00:00Z', finished_at: '2026-10-20T08:00:05Z', next_retry_at: null, result: { calculated: 14, insufficient: 0 }, error: null },
      ], meta: { has_more: false } }
    } else if (path === '/automations' || path.startsWith('/automations/settings') || path.startsWith('/automations/insights_refresh')) body = catalog()
    return new Response(JSON.stringify(body), { status: 200 })
  }))
})
afterEach(() => vi.unstubAllGlobals())

const renderPage = () => render(<AuthProvider><MemoryRouter><AutomationsPage /></MemoryRouter></AuthProvider>)

describe('automatizaciones', () => {
  it('muestra cada automatización con su estado, su última ejecución y las que están en preparación', async () => {
    renderPage()

    expect(await screen.findByRole('heading', { name: 'Recálculo de la inteligencia comercial' })).toBeInTheDocument()
    expect(screen.getByText('Última ejecución:', { exact: false })).toHaveTextContent('Con error')
    expect(screen.getByText('Última ejecución:', { exact: false })).toHaveTextContent('El servicio no respondió')
    const prep = screen.getByRole('heading', { name: 'Acciones de seguimiento del día y vencidas' }).closest('div')?.parentElement
    expect(prep).toHaveTextContent('En preparación')
    expect(within(prep as HTMLElement).queryByRole('button', { name: /Ejecutar ahora/ })).not.toBeInTheDocument()
    expect(screen.getByText(/se reintenta 2 veces \(5 y 15 minutos después\)/)).toBeInTheDocument()
  })

  it('pausa una automatización con su casilla', async () => {
    const u = userEvent.setup()
    renderPage()

    await u.click(await screen.findByRole('checkbox', { name: 'Activa: Recálculo de la inteligencia comercial' }))
    await waitFor(() => expect(calls.find((c) => c.method === 'PUT')).toMatchObject({ path: '/automations/insights_refresh', body: { enabled: false } }))
  })

  it('guarda solo el parámetro que cambió y ejecuta a mano', async () => {
    const u = userEvent.setup()
    renderPage()

    const hour = await screen.findByLabelText('Hora de ejecución (hora (0 a 23))')
    const save = screen.getByRole('button', { name: 'Guardar parámetros' })
    expect(save).toBeDisabled()
    await u.clear(hour)
    await u.type(hour, '4')
    await u.click(save)
    await waitFor(() => expect(calls.find((c) => c.method === 'PUT')?.body).toEqual({ params: { hour: '4' } }))

    await u.click(screen.getByRole('button', { name: 'Ejecutar ahora: Recálculo de la inteligencia comercial' }))
    await waitFor(() => expect(calls.some((c) => c.path === '/automations/insights_refresh/run' && c.method === 'POST')).toBe(true))
  })

  it('el interruptor general y la ventana se guardan, y avisa cuando todo está pausado', async () => {
    masterEnabled = false
    const u = userEvent.setup()
    renderPage()

    expect(await screen.findByText(/Todas las automatizaciones están pausadas/)).toBeInTheDocument()
    await u.click(screen.getByRole('checkbox', { name: /interruptor general/ }))
    await waitFor(() => expect(calls.find((c) => c.path === '/automations/settings')?.body).toEqual({ master_enabled: true }))
    await u.click(screen.getByRole('button', { name: 'Guardar ventana' }))
    await waitFor(() => expect(calls.filter((c) => c.path === '/automations/settings').at(-1)?.body).toEqual({ window: { start: 7, end: 19 } }))
  })

  it('el registro muestra el resultado, los intentos y cuándo se reintenta', async () => {
    renderPage()

    const table = await screen.findByRole('table', { name: 'Registro de ejecuciones' })
    await waitFor(() => expect(table).toHaveTextContent('Intento 2'))
    expect(table).toHaveTextContent('Se reintentará el')
    expect(table).toHaveTextContent('calculated: 14 · insufficient: 0')
    expect(table).toHaveTextContent('Manual')
  })
})
