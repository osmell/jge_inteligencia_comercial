import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { AuthProvider } from './auth/AuthContext'
import CustomerSegment from './components/CustomerSegment'
import InsightsPage from './pages/InsightsPage'
import InsightsSegmentsPage from './pages/InsightsSegmentsPage'

let permissions: string[]
let puts: { path: string; body: { values: Record<string, string> } }[]
let posts: string[]
let run: unknown

beforeEach(() => {
  permissions = ['insights.view']
  puts = []
  posts = []
  run = { id: 1, status: 'ok', computed_at: '2026-10-20T02:00:00Z', period_from: '2025-10-21', period_to: '2026-10-20', note: null, customers: 26, parameters: {} }
  document.cookie = 'XSRF-TOKEN=x'
  vi.stubGlobal('fetch', vi.fn(async (url: string, init?: RequestInit) => {
    const path = url.replace('http://localhost:8000/api/v1', '')
    if (init?.method === 'POST') {
      posts.push(path)
      return new Response(JSON.stringify({ data: {} }), { status: 200 })
    }
    if (init?.method === 'PUT') {
      puts.push({ path, body: JSON.parse(String(init.body)) })
      return new Response(JSON.stringify({ data: [] }), { status: 200 })
    }
    let body: unknown = { data: [] }
    if (path === '/auth/me') body = { data: { id: 1, username: 'a', name: 'Ana', role: { slug: 'administrador', name: 'Administrador' }, permissions, companies: [], branches: [] } }
    else if (path === '/companies') body = { data: [{ id: 1, code: 'A', name: 'Alfa', is_active: true }] }
    else if (path.startsWith('/insights/rfm/customer')) {
      body = { data: path.includes('NADIE') ? null : { code: 'C25', name: 'Cliente C25', segment: 'champion', segment_label: 'Campeones', first_purchase: '2026-04-01', last_purchase: '2026-10-19', recency_days: 1, frequency: 25, gross: '2500.0000', returns: '0.0000', monetary: '2500.0000', r: 5, f: 5, m: 5, explanation: 'Compró por última vez hace 1 días (recencia 5/5).' }, meta: { run } }
    } else if (path.startsWith('/insights/rfm')) {
      body = {
        data: [
          { code: 'C25', name: 'Cliente C25', segment: 'champion', segment_label: 'Campeones', first_purchase: '2026-04-01', last_purchase: '2026-10-19', recency_days: 1, frequency: 25, gross: '2500.0000', returns: '500.0000', monetary: '2000.0000', r: 5, f: 5, m: 4, explanation: 'Compró por última vez hace 1 días (recencia 5/5), tuvo 25 días con compra.' },
          { code: 'C01', name: 'Cliente C01', segment: 'lost', segment_label: 'Perdidos', first_purchase: '2026-04-09', last_purchase: '2026-04-09', recency_days: 194, frequency: 1, gross: '100.0000', returns: '0.0000', monetary: '100.0000', r: 1, f: 1, m: 1, explanation: 'Compró por última vez hace 194 días.' },
        ],
        meta: { page: 1, has_more: false, run, counts: { new: 0, champion: 1, loyal: 0, promising: 0, at_risk: 0, dormant: 0, lost: 1 }, segments: [
          { key: 'new', label: 'Nuevos', rule: 'Primera compra reciente.' }, { key: 'champion', label: 'Campeones', rule: 'Recientes y frecuentes.' }, { key: 'loyal', label: 'Leales', rule: 'Regulares.' },
          { key: 'promising', label: 'Prometedores', rule: 'Recientes pero con poco valor.' }, { key: 'at_risk', label: 'En riesgo', rule: 'Buenos clientes que se enfriaron.' }, { key: 'dormant', label: 'Dormidos', rule: 'Poco valor y tiempo sin comprar.' }, { key: 'lost', label: 'Perdidos', rule: 'Los que más llevan sin comprar.' },
        ] },
      }
    } else if (path === '/insights/status') {
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

  it('la segmentación lista los clientes con su explicación y filtra por segmento', async () => {
    const u = userEvent.setup()
    render(<AuthProvider><MemoryRouter><InsightsSegmentsPage /></MemoryRouter></AuthProvider>)

    const table = await screen.findByRole('table', { name: 'Clientes por segmento' })
    await waitFor(() => expect(table).toHaveTextContent('Cliente C25'))
    expect(table).toHaveTextContent('Compró por última vez hace 1 días')
    expect(table).toHaveTextContent('tras 500,00 de devoluciones')
    expect(table).toHaveTextContent('5 · 5 · 4')
    expect(screen.getByText(/Calculado el/)).toHaveTextContent('26 clientes con compras')
    expect(screen.getByText(/distinta del scoring de crédito/)).toBeInTheDocument()
    await u.click(within(screen.getByLabelText('Resumen por segmento')).getByRole('button', { name: /Perdidos/ }))
    expect(await screen.findByText('Los que más llevan sin comprar.')).toBeInTheDocument()
  })

  it('sin cálculo suficiente lo explica y no muestra segmentos', async () => {
    run = { id: 2, status: 'insufficient', computed_at: '2026-10-20T02:00:00Z', period_from: '2025-10-21', period_to: '2026-10-20', note: 'Hay 3 clientes con compras en el periodo; se necesitan al menos 20.', customers: 3, parameters: {} }
    render(<AuthProvider><MemoryRouter><InsightsSegmentsPage /></MemoryRouter></AuthProvider>)

    expect(await screen.findByText(/se necesitan al menos 20/)).toBeInTheDocument()
    expect(screen.queryByRole('table', { name: 'Clientes por segmento' })).not.toBeInTheDocument()
  })

  it('solo quien gestiona puede recalcular', async () => {
    const u = userEvent.setup()
    const { unmount } = render(<AuthProvider><MemoryRouter><InsightsSegmentsPage /></MemoryRouter></AuthProvider>)
    await screen.findByRole('table', { name: 'Clientes por segmento' })
    expect(screen.queryByRole('button', { name: 'Recalcular' })).not.toBeInTheDocument()
    unmount()

    permissions = ['insights.view', 'insights.manage']
    render(<AuthProvider><MemoryRouter><InsightsSegmentsPage /></MemoryRouter></AuthProvider>)
    await u.click(await screen.findByRole('button', { name: 'Recalcular' }))
    await waitFor(() => expect(posts).toEqual(['/insights/rfm/recalculate']))
  })

  it('la ficha del cliente muestra su segmento y por qué', async () => {
    render(<AuthProvider><MemoryRouter><CustomerSegment companyId="1" code="C25" /></MemoryRouter></AuthProvider>)

    expect(await screen.findByText('Campeones')).toBeInTheDocument()
    expect(screen.getByText(/Compró por última vez hace 1 días \(recencia 5\/5\)/)).toBeInTheDocument()
    expect(screen.getByText(/Es distinto del scoring de crédito/)).toBeInTheDocument()
  })
})
