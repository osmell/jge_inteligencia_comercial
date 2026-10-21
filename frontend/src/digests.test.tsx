import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { AuthProvider } from './auth/AuthContext'
import DigestDetailPage from './pages/DigestDetailPage'
import DigestsPage from './pages/DigestsPage'

let permissions: string[]
let calls: { path: string; method: string; body: unknown }[]
let companies: { id: number; name: string }[]

beforeEach(() => {
  document.cookie = 'XSRF-TOKEN=x'
  permissions = ['digests.view']
  calls = []
  companies = [{ id: 1, name: 'Alfa' }]
  vi.stubGlobal('fetch', vi.fn(async (url: string, init?: RequestInit) => {
    const path = url.replace('http://localhost:8000/api/v1', '')
    const method = init?.method ?? 'GET'
    calls.push({ path, method, body: init?.body ? JSON.parse(String(init.body)) : undefined })
    let body: unknown = { data: [] }
    if (path === '/auth/me') body = { data: { id: 1, username: 'a', name: 'Ana', role: { slug: 'gerente_general', name: 'Gerente' }, permissions, companies: [], branches: [] } }
    else if (path === '/digests/7') {
      body = { data: {
        id: 7, company_id: 1, kind: 'weekly', period_key: '2026-W42', period_from: '2026-10-12', period_to: '2026-10-18', title: 'Resumen semanal de Alfa: 12/10 al 18/10/2026', generated_at: '2026-10-19T12:00:00Z',
        sections: [
          { key: 'sales', title: 'Ventas de la semana', metrics: [{ label: 'Venta neta de la semana', value: 5300, format: 'money', tone: 'good', hint: 'Semana anterior: 4.000,00 · +32,5 %' }, { label: 'Facturas', value: 3, format: 'int' }],
            tables: [{ title: 'Principales clientes', columns: [{ key: 'name', label: 'Cliente', format: 'text' }, { key: 'net', label: 'Venta neta', format: 'money' }], rows: [{ name: 'Cliente C3', net: 4000 }, { name: 'Cliente C1', net: 1000 }] }] },
          { key: 'credit', title: 'Cartera', metrics: [{ label: 'Saldo vencido (con 1 día o más)', value: 1500, format: 'money', tone: 'bad' }, { label: 'Cumplimiento del equipo', value: 82.5, format: 'pct' }] },
        ],
      } }
    } else if (path.startsWith('/digests/generate')) body = { data: { id: 8 } }
    else if (path.startsWith('/digests')) {
      body = { data: [
        { id: 7, company_id: 1, kind: 'weekly', period_key: '2026-W42', period_from: '2026-10-12', period_to: '2026-10-18', title: 'Resumen semanal de Alfa: 12/10 al 18/10/2026', generated_at: '2026-10-19T12:00:00Z' },
        { id: 6, company_id: 1, kind: 'daily', period_key: '2026-10-20', period_from: '2026-10-20', period_to: '2026-10-20', title: 'Resumen diario de Alfa: martes 20/10/2026', generated_at: '2026-10-21T12:00:00Z' },
      ], meta: { has_more: false, companies } }
    }
    return new Response(JSON.stringify(body), { status: 200 })
  }))
})
afterEach(() => vi.unstubAllGlobals())

describe('resúmenes para gerencia', () => {
  it('lista los resúmenes con su tipo y periodo y enlaza al detalle', async () => {
    render(<AuthProvider><MemoryRouter><DigestsPage /></MemoryRouter></AuthProvider>)

    const table = await screen.findByRole('table', { name: 'Resúmenes' })
    await waitFor(() => expect(table).toHaveTextContent('Resumen semanal de Alfa'))
    expect(table).toHaveTextContent('Semanal')
    expect(table).toHaveTextContent('Diario')
    expect(within(table).getByRole('link', { name: 'Resumen diario de Alfa: martes 20/10/2026' })).toHaveAttribute('href', '/resumenes/6')
    expect(screen.queryByRole('button', { name: 'Generar el diario' })).not.toBeInTheDocument()
  })

  it('quien administra las automatizaciones puede generar el resumen del periodo', async () => {
    permissions = ['digests.view', 'automations.manage']
    const u = userEvent.setup()
    render(<AuthProvider><MemoryRouter><DigestsPage /></MemoryRouter></AuthProvider>)

    await u.click(await screen.findByRole('button', { name: 'Generar el semanal' }))
    await waitFor(() => expect(calls.find((c) => c.path === '/digests/generate')?.body).toEqual({ company_id: 1, kind: 'weekly' }))
  })

  it('sin acceso completo a ninguna compañía lo explica', async () => {
    companies = []
    render(<AuthProvider><MemoryRouter><DigestsPage /></MemoryRouter></AuthProvider>)

    expect(await screen.findByText(/solo los ve quien tiene acceso completo a ella/)).toBeInTheDocument()
  })

  it('el detalle muestra las secciones con sus cifras, su tono y sus tablas', async () => {
    render(<AuthProvider><MemoryRouter initialEntries={['/resumenes/7']}><Routes><Route path="/resumenes/:id" element={<DigestDetailPage />} /></Routes></MemoryRouter></AuthProvider>)

    expect(await screen.findByRole('heading', { name: 'Resumen semanal de Alfa: 12/10 al 18/10/2026' })).toBeInTheDocument()
    const sales = screen.getByRole('region', { name: 'Ventas de la semana' })
    expect(sales.querySelector('p.tabular')).toHaveTextContent('5300,00')
    expect(within(sales).getByText('Semana anterior: 4.000,00 · +32,5 %')).toBeInTheDocument()
    expect(within(sales).getByRole('table', { name: 'Principales clientes' })).toHaveTextContent('Cliente C3')
    const credit = screen.getByRole('region', { name: 'Cartera' })
    expect(within(credit).getByText('Saldo vencido (con 1 día o más)').closest('div')).toHaveTextContent('1500,00')
    expect(within(credit).getByText('Cumplimiento del equipo').closest('div')).toHaveTextContent('82,5 %')
    expect(screen.getByText(/Las cifras quedan tal como estaban al generarse/)).toBeInTheDocument()
  })
})
