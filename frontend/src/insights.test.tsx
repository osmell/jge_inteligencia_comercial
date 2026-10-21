import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { AuthProvider } from './auth/AuthContext'
import InsightsPage from './pages/InsightsPage'

let permissions: string[]
let puts: { path: string; body: { values: Record<string, string> } }[]

beforeEach(() => {
  permissions = ['insights.view']
  puts = []
  document.cookie = 'XSRF-TOKEN=x'
  vi.stubGlobal('fetch', vi.fn(async (url: string, init?: RequestInit) => {
    const path = url.replace('http://localhost:8000/api/v1', '')
    if (init?.method === 'PUT') {
      puts.push({ path, body: JSON.parse(String(init.body)) })
      return new Response(JSON.stringify({ data: [] }), { status: 200 })
    }
    let body: unknown = { data: [] }
    if (path === '/auth/me') body = { data: { id: 1, username: 'a', name: 'Ana', role: { slug: 'administrador', name: 'Administrador' }, permissions, companies: [], branches: [] } }
    else if (path === '/insights/status') {
      body = { data: [{
        company_id: 1, company: 'Alfa', first_sale: '2026-01-05', last_sale: '2026-06-01', history_months: 5, documents: 300, stale: true, days_since_last_sale: 141,
        analyses: [
          { key: 'rfm', label: 'Segmentación RFM', available: false, reason: 'Historial insuficiente: tiene 5 de 6 meses de historial.', required_months: 6, required_documents: null, last_run: null },
          { key: 'concentration', label: 'Concentración de clientes y productos', available: true, reason: null, required_months: 3, required_documents: null, last_run: { computed_at: '2026-10-19T03:00:00Z', status: 'ok', period_from: '2026-01-05', period_to: '2026-06-01' } },
        ],
      }] }
    } else if (path.startsWith('/insights/runs')) {
      body = { data: [{ id: 1, company_id: 1, analysis: 'concentration', status: 'ok', period_from: '2026-01-05', period_to: '2026-06-01', computed_at: '2026-10-19T03:00:00Z', note: null }] }
    } else if (path === '/insights/parameters') {
      body = { data: [
        { key: 'rfm.period_months', group: 'RFM', label: 'Periodo de análisis', unit: 'meses', help: 'Meses que se miden.', min: 3, max: 36, default: 12, value: 12, customized: false },
        { key: 'anomaly.drop_pct', group: 'Cambios anormales', label: 'Caída que genera aviso', unit: '%', help: 'Caída contra su referencia.', min: 5, max: 100, default: 40, value: 40, customized: false },
      ] }
    }
    return new Response(JSON.stringify(body), { status: 200 })
  }))
})
afterEach(() => vi.unstubAllGlobals())

const renderPage = () => render(<AuthProvider><MemoryRouter><InsightsPage /></MemoryRouter></AuthProvider>)

describe('inteligencia comercial', () => {
  it('explica qué análisis están disponibles y por qué otros no', async () => {
    renderPage()

    const list = await screen.findByRole('list', { name: 'Análisis de Alfa' })
    expect(list).toHaveTextContent('Historial insuficiente: tiene 5 de 6 meses de historial.')
    expect(within(list).getByText('Segmentación RFM').closest('li')).toHaveTextContent('Falta historial')
    expect(within(list).getByText('Concentración de clientes y productos').closest('li')).toHaveTextContent('Disponible')
    expect(list).toHaveTextContent('Último cálculo')
    expect(screen.getByText(/5 meses/)).toBeInTheDocument()
    expect(screen.getByText(/La última venta es de hace 141 días/)).toBeInTheDocument()
    expect(screen.getByText(/Las recomendaciones son sugerencias, no decisiones automáticas/)).toBeInTheDocument()
  })

  it('muestra el registro de cálculos', async () => {
    renderPage()

    const table = await screen.findByRole('table', { name: 'Registro de cálculos' })
    await waitFor(() => expect(table).toHaveTextContent('concentration'))
    expect(table).toHaveTextContent('Calculado')
  })

  it('con solo consulta los parámetros no se pueden editar', async () => {
    renderPage()

    const field = await screen.findByLabelText('Periodo de análisis (meses)')
    expect(field).toBeDisabled()
    expect(screen.queryByRole('button', { name: 'Guardar parámetros' })).not.toBeInTheDocument()
  })

  it('con permiso de gestión guarda solo los parámetros que cambió', async () => {
    permissions = ['insights.view', 'insights.manage']
    const u = userEvent.setup()
    renderPage()

    const save = await screen.findByRole('button', { name: 'Guardar parámetros' })
    expect(save).toBeDisabled()
    const field = await screen.findByLabelText('Caída que genera aviso (%)')
    await u.clear(field)
    await u.type(field, '50')
    await u.click(save)

    await waitFor(() => expect(puts).toEqual([{ path: '/insights/parameters', body: { values: { 'anomaly.drop_pct': '50' } } }]))
  })
})
