import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { AuthProvider } from './auth/AuthContext'
import CustomerAlerts from './components/CustomerAlerts'
import CustomerCrossSell from './components/CustomerCrossSell'
import CustomerSegment from './components/CustomerSegment'
import InsightsConcentrationPage from './pages/InsightsConcentrationPage'
import InsightsAlertsPage from './pages/InsightsAlertsPage'
import InsightsCrossSellPage from './pages/InsightsCrossSellPage'
import InsightsPage from './pages/InsightsPage'
import InsightsSegmentsPage from './pages/InsightsSegmentsPage'

let permissions: string[]
let puts: { path: string; body: { values: Record<string, string> } }[]
let posts: string[]
let run: unknown
let urls: string[]
let crossRun: unknown
let concentration: unknown
let cohorts: unknown

beforeEach(() => {
  permissions = ['insights.view']
  puts = []
  posts = []
  urls = []
  const block = (entity: string) => ({
    count: 5, total: '10000.0000', top1_pct: 80, top10_pct: 100, n80: 1, n80_pct: 20, hhi: 6538,
    top: [{ code: 'C1', name: `${entity} uno`, net: '8000.0000', share_pct: 80, cumulative_pct: 80 }, { code: 'C2', name: `${entity} dos`, net: '1000.0000', share_pct: 10, cumulative_pct: 90 }],
    curve: [5, 10, 15, 20, 25, 100].map((e) => ({ entities_pct: e, sales_pct: e < 20 ? 40 : 80 })), alerts: entity === 'Cliente' ? [{ code: 'C1', name: 'Cliente uno', net: '8000.0000', share_pct: 80, cumulative_pct: 80 }] : [],
  })
  crossRun = { id: 9, status: 'ok', computed_at: '2026-10-20T02:00:00Z', period_from: '2025-10-21', period_to: '2026-10-20', note: null, customers: 40, rules: 2, opportunities: 10, level: 'categoría', parameters: { 'cross_sell.min_support_customers': 20, 'cross_sell.min_confidence_pct': 30 } }
  concentration = { id: 5, status: 'ok', computed_at: '2026-10-20T02:00:00Z', period_from: '2025-10-21', period_to: '2026-10-20', note: null, parameters: {}, summary: { alert_pct: 15, customers: block('Cliente'), products: block('Producto') } }
  cohorts = {
    id: 6, status: 'ok', computed_at: '2026-10-20T02:00:00Z', period_from: '2026-01-10', period_to: '2026-08-10', note: null, parameters: {},
    summary: { depth: 3, first_month: '2026-01', last_month: '2026-08', cohorts: [
      { month: '2026-01', customers: 1, prior: true, retention: [100, 100, 0, null] },
      { month: '2026-02', customers: 2, prior: false, retention: [100, 50, 50, 50] },
    ], average: [100, 50, 50, 50] },
  }
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
    urls.push(path)
    let body: unknown = { data: [] }
    if (path === '/auth/me') body = { data: { id: 1, username: 'a', name: 'Ana', role: { slug: 'administrador', name: 'Administrador' }, permissions, companies: [], branches: [] } }
    else if (path.startsWith('/insights/alerts/customer')) {
      body = { data: path.includes('C05') ? [] : [{ id: 1, code: 'R1', name: 'Cliente R1', type: 'inactivity_risk', severity: 'high', status: 'open', explanation: 'Suele comprar cada 7 días y lleva 38 días sin comprar.', facts: {}, first_detected_at: '2026-10-20T02:00:00Z', last_detected_at: '2026-10-20T02:00:00Z', run_id: 3 }] }
    } else if (path.startsWith('/insights/alerts')) {
      body = {
        data: [
          { id: 1, code: 'R1', name: 'Cliente R1', type: 'inactivity_risk', severity: 'high', status: 'open', explanation: 'Suele comprar cada 7 días y lleva 38 días sin comprar.', facts: {}, first_detected_at: '2026-10-20T02:00:00Z', last_detected_at: '2026-10-20T02:00:00Z', run_id: 3 },
          { id: 2, code: 'D1', name: 'Cliente D1', type: 'sales_drop', severity: 'medium', status: 'open', explanation: 'En 2026-09 compró 400,00, un 60 % menos que su promedio.', facts: {}, first_detected_at: '2026-10-20T02:00:00Z', last_detected_at: '2026-10-20T02:00:00Z', run_id: 4 },
        ],
        meta: { page: 1, has_more: false, counts: { inactivity_risk: 1, sales_drop: 1, sales_rise: 0, abandoned_line: 0 } },
      }
    } else if (path.startsWith('/insights/cross-sell/opportunities')) {
      body = { data: [{ code: 'C21', name: 'Cliente C21', suggestion: 'Frenos', because: 'Filtros', confidence_pct: 66.7, support: 20, lift: 1.33, explanation: 'Compra «Filtros» y todavía no «Frenos». 20 de 30 clientes que compran «Filtros» (66,7 %) también compran «Frenos».' }], meta: { has_more: false, total: 10, run: crossRun } }
    } else if (path.startsWith('/insights/cross-sell/customer')) {
      body = { data: path.includes('C05') ? [] : [{ code: 'C21', name: 'Cliente C21', suggestion: 'Frenos', because: 'Filtros', confidence_pct: 66.7, support: 20, lift: 1.33, explanation: 'Compra «Filtros» y todavía no «Frenos».' }], meta: { run: crossRun } }
    } else if (path.startsWith('/insights/cross-sell')) {
      body = { data: [
        { id: 1, antecedent: 'Frenos', consequent: 'Filtros', antecedent_customers: 20, consequent_customers: 30, both_customers: 20, total_customers: 40, confidence_pct: 100, lift: 1.33, explanation: '20 de 20 clientes que compran «Frenos» (100,0 %) también compran «Filtros».' },
        { id: 2, antecedent: 'Filtros', consequent: 'Frenos', antecedent_customers: 30, consequent_customers: 20, both_customers: 20, total_customers: 40, confidence_pct: 66.7, lift: 1.33, explanation: '20 de 30 clientes que compran «Filtros» (66,7 %) también compran «Frenos».' },
      ], meta: { run: crossRun } }
    } else if (path.startsWith('/insights/concentration')) body = { data: concentration }
    else if (path.startsWith('/insights/cohorts')) body = { data: cohorts }
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

  it('la concentración muestra los pesos, la curva de Pareto y el aviso de dependencia', async () => {
    render(<AuthProvider><MemoryRouter><InsightsConcentrationPage /></MemoryRouter></AuthProvider>)

    expect(await screen.findByText(/Dependencia de clientes \(informativo\)/)).toHaveTextContent('Cliente uno pesa 80 %')
    expect(screen.getByText(/no una acción automática|no es una acción automática/i)).toBeInTheDocument()
    const customers = within(screen.getByRole('region', { name: 'Clientes' }))
    expect(customers.getByText('Peso del cliente principal').closest('div')).toHaveTextContent('80 %')
    expect(customers.getByText('Suman el 80 % de la venta').closest('div')).toHaveTextContent('1 clientes')
    expect(customers.getByText(/Índice de concentración/).closest('div')).toHaveTextContent('6538')
    expect(customers.getByRole('img', { name: /Porcentaje acumulado de la venta según el porcentaje de clientes/ })).toBeInTheDocument()
    expect(customers.getByRole('table', { name: 'Principales clientes' })).toHaveTextContent('Cliente uno')
    expect(within(screen.getByRole('region', { name: 'Productos' })).getByRole('table', { name: 'Principales productos' })).toHaveTextContent('Producto dos')
  })

  it('las cohortes muestran la retención y explican la cohorte previa y el mes incompleto', async () => {
    render(<AuthProvider><MemoryRouter><InsightsConcentrationPage /></MemoryRouter></AuthProvider>)

    const table = await screen.findByRole('table', { name: 'Retención por cohorte' })
    expect(table).toHaveTextContent('Incluye clientes previos al historial')
    expect(table).toHaveTextContent('Promedio ponderado')
    expect(within(table).getByText('2026-02').closest('tr')).toHaveTextContent('50 %')
    expect(screen.getByText(/el mes de la última venta está incompleto/)).toBeInTheDocument()
  })

  it('sin historial suficiente lo explica y no muestra resultados', async () => {
    concentration = { id: 7, status: 'insufficient', computed_at: '2026-10-20T02:00:00Z', period_from: null, period_to: null, note: 'Historial insuficiente para medir la concentración.', summary: null, parameters: {} }
    cohorts = null
    render(<AuthProvider><MemoryRouter><InsightsConcentrationPage /></MemoryRouter></AuthProvider>)

    expect(await screen.findByText(/Historial insuficiente para medir la concentración/)).toBeInTheDocument()
    expect(await screen.findByText(/Todavía no se ha calculado/)).toBeInTheDocument()
    expect(screen.queryByRole('table', { name: 'Retención por cohorte' })).not.toBeInTheDocument()
  })

  it('recalcular concentración y cohortes requiere el permiso de gestión', async () => {
    permissions = ['insights.view', 'insights.manage']
    const u = userEvent.setup()
    render(<AuthProvider><MemoryRouter><InsightsConcentrationPage /></MemoryRouter></AuthProvider>)

    await u.click(await screen.findByRole('button', { name: 'Recalcular' }))
    await waitFor(() => expect(posts.sort()).toEqual(['/insights/cohorts/recalculate', '/insights/concentration/recalculate']))
  })

  it('la venta cruzada muestra las oportunidades con su explicación y las reglas que las respaldan', async () => {
    render(<AuthProvider><MemoryRouter><InsightsCrossSellPage /></MemoryRouter></AuthProvider>)

    const table = await screen.findByRole('table', { name: 'Oportunidades de venta cruzada' })
    await waitFor(() => expect(table).toHaveTextContent('Cliente C21'))
    expect(table).toHaveTextContent('Compra «Filtros» y todavía no «Frenos»')
    expect(table).toHaveTextContent('66,7 %')
    const rules = await screen.findByRole('table', { name: 'Reglas de asociación' })
    expect(rules).toHaveTextContent('20 de 20')
    expect(screen.getByText(/a nivel de categoría: 2 reglas y 10 oportunidades entre 40 clientes/)).toBeInTheDocument()
    expect(screen.getByText(/Son sugerencias para el vendedor, no decisiones automáticas/)).toBeInTheDocument()
  })

  it('sin datos suficientes la venta cruzada lo explica', async () => {
    crossRun = { id: 10, status: 'insufficient', computed_at: '2026-10-20T02:00:00Z', period_from: null, period_to: null, note: 'Historial o facturas insuficientes para la venta cruzada.', customers: null, rules: null, opportunities: null, level: 'categoría', parameters: {} }
    render(<AuthProvider><MemoryRouter><InsightsCrossSellPage /></MemoryRouter></AuthProvider>)

    expect(await screen.findByText(/Historial o facturas insuficientes/)).toBeInTheDocument()
    expect(screen.queryByRole('table', { name: 'Reglas de asociación' })).not.toBeInTheDocument()
  })

  it('la ficha del cliente sugiere qué ofrecer, y avisa cuando no hay sugerencia', async () => {
    const { unmount } = render(<AuthProvider><MemoryRouter><CustomerCrossSell companyId="1" code="C21" /></MemoryRouter></AuthProvider>)
    const list = await screen.findByRole('list', { name: 'Oportunidades del cliente' })
    expect(list).toHaveTextContent('Ofrecer')
    expect(list).toHaveTextContent('Frenos')
    expect(screen.getByText(/no se registra ningún contacto ni venta por sí sola/)).toBeInTheDocument()
    unmount()

    render(<AuthProvider><MemoryRouter><CustomerCrossSell companyId="1" code="C05" /></MemoryRouter></AuthProvider>)
    expect(await screen.findByText(/no hay una categoría para sugerirle/)).toBeInTheDocument()
  })

  it('solo quien gestiona puede recalcular la venta cruzada', async () => {
    permissions = ['insights.view', 'insights.manage']
    const u = userEvent.setup()
    render(<AuthProvider><MemoryRouter><InsightsCrossSellPage /></MemoryRouter></AuthProvider>)

    await u.click(await screen.findByRole('button', { name: 'Recalcular' }))
    await waitFor(() => expect(posts).toEqual(['/insights/cross-sell/recalculate']))
  })

  it('las alertas muestran cada aviso con su explicación y filtran por tipo', async () => {
    const u = userEvent.setup()
    render(<AuthProvider><MemoryRouter><InsightsAlertsPage /></MemoryRouter></AuthProvider>)

    const table = await screen.findByRole('table', { name: 'Alertas de clientes' })
    await waitFor(() => expect(table).toHaveTextContent('Cliente R1'))
    expect(table).toHaveTextContent('Suele comprar cada 7 días y lleva 38 días sin comprar')
    expect(table).toHaveTextContent('Riesgo de inactividad')
    expect(within(screen.getByLabelText('Alertas por tipo')).getByRole('button', { name: /Caída de venta/ })).toHaveTextContent('1')
    expect(screen.getByText(/Son recomendaciones, no decisiones automáticas/)).toBeInTheDocument()
    await u.click(within(screen.getByLabelText('Alertas por tipo')).getByRole('button', { name: /Caída de venta/ }))
    await waitFor(() => expect(urls.filter((x) => x.startsWith('/insights/alerts')).at(-1)).toContain('type=sales_drop'))
  })

  it('la ficha muestra las alertas abiertas y se oculta si no hay', async () => {
    const { unmount } = render(<AuthProvider><MemoryRouter><CustomerAlerts companyId="1" code="R1" /></MemoryRouter></AuthProvider>)
    const list = await screen.findByRole('list', { name: 'Alertas abiertas del cliente' })
    expect(list).toHaveTextContent('Riesgo de inactividad')
    expect(list).toHaveTextContent('lleva 38 días sin comprar')
    unmount()

    render(<AuthProvider><MemoryRouter><CustomerAlerts companyId="1" code="C05" /></MemoryRouter></AuthProvider>)
    await waitFor(() => expect(urls.some((x) => x.includes('code=C05'))).toBe(true))
    expect(screen.queryByText('Alertas del cliente')).not.toBeInTheDocument()
  })
})
