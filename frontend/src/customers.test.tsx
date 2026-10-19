import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import CustomerProfilePage from './pages/CustomerProfilePage'
import CustomersPage from './pages/CustomersPage'
import type { CustomerProfile } from './lib/customers'

const kpis = {
  net_total: '650.00', tax_total: '78.00', grand_total: '728.00', units: '8.00', documents: 3, customers: 1,
  average_ticket: '216.67', estimated_margin: '325.00', estimated_margin_pct: 50, cost_coverage_pct: 100,
}
const brow = (key: string, label: string, net: string) => ({ key, label, ...kpis, net_total: net })

const profile = (o: Partial<CustomerProfile> = {}): CustomerProfile => ({
  code: 'C1', name: 'Taller Pérez', name_variants: [{ name: 'Taller Pérez', documents: 3, last_seen: '2026-08-31' }],
  companies: [{ id: 1, name: 'Compañía Alfa', documents: 3 }], first_purchase: '2026-08-01', last_purchase: '2026-08-31',
  days_since_last_purchase: 50, history_starts_on: '2025-11-01', totals: kpis, frequency: { purchase_days: 3, average_days_between: 15 },
  monthly: [{ key: '2026-09', net_total: '0.00' }, { key: '2026-10', net_total: '650.00' }],
  top_products: [brow('P1', 'Filtro de aceite', '300.00')], top_categories: [brow('Filtros', 'Filtros', '300.00')],
  sellers: [brow('1', 'Ana', '350.00')], branches: [brow('1', 'Centro', '650.00')], ...o,
})

let urls: string[]
let respond: (path: string) => unknown

beforeEach(() => {
  urls = []
  vi.stubGlobal('fetch', vi.fn(async (url: string) => {
    const path = url.replace('http://localhost:8000/api/v1', '')
    urls.push(path)
    return new Response(JSON.stringify(respond(path)), { status: 200 })
  }))
  respond = (path) => {
    if (path.startsWith('/companies')) return { data: [{ id: 1, code: 'A', name: 'Compañía Alfa', is_active: true }] }
    if (path.startsWith('/customers/profile')) return { data: profile() }
    if (path.startsWith('/customers/purchases')) {
      return { data: [{ id: 5, document: 'S-N1', issued_on: '2026-08-01', branch: 'Centro', seller: 'Ana', lines: 2, net_total: '150.00', grand_total: '168.00' }], meta: { page: 1, has_more: false } }
    }
    if (path.startsWith('/customers/document')) {
      return { data: [{ line: 1, product_code: 'P1', product_name: 'Filtro de aceite', category: 'Filtros', units: '2.00', net_total: '100.00', grand_total: '112.00' }] }
    }
    if (path.startsWith('/customers')) {
      return { data: [{ code: 'C1', name: 'Taller Pérez', documents: 3, net_total: '650.00', first_purchase: '2026-08-01', last_purchase: '2026-08-31' }], meta: { page: 1, has_more: true } }
    }
    return { data: [] }
  }
})
afterEach(() => vi.unstubAllGlobals())

const renderProfile = (search = '?codigo=C1') =>
  render(<MemoryRouter initialEntries={[`/clientes/ficha${search}`]}><Routes><Route path="/clientes/ficha" element={<CustomerProfilePage />} /></Routes></MemoryRouter>)

describe('listado de clientes', () => {
  it('lista, enlaza a la ficha y busca esperando a que termine de escribir', async () => {
    const u = userEvent.setup()
    render(<MemoryRouter><CustomersPage /></MemoryRouter>)

    const table = await screen.findByRole('table', { name: 'Clientes' })
    expect(within(table).getByRole('link', { name: 'Taller Pérez' })).toHaveAttribute('href', '/clientes/ficha?codigo=C1')
    expect(screen.getByRole('button', { name: 'Siguiente' })).toBeEnabled()
    expect(screen.getByRole('button', { name: 'Anterior' })).toBeDisabled()

    await u.type(screen.getByLabelText('Buscar por código o nombre'), 'taller')
    await waitFor(() => expect(urls.at(-1)).toContain('q=taller'))
    expect(urls.filter((x) => x.startsWith('/customers?') && x.includes('q=')).every((x) => x.includes('q=taller'))).toBe(true)

    await u.click(screen.getByRole('button', { name: 'Siguiente' }))
    await waitFor(() => expect(urls.at(-1)).toContain('page=2'))
  })
})

describe('ficha de cliente', () => {
  it('muestra totales, frecuencia, avisos de historial y detalle de una compra', async () => {
    const u = userEvent.setup()
    renderProfile()

    expect(await screen.findByRole('heading', { name: 'Taller Pérez' })).toBeInTheDocument()
    expect(screen.getByText('Venta neta total', { selector: 'p' }).closest('div')).toHaveTextContent('650,00')
    expect(screen.getByText('cada 15 días')).toBeInTheDocument()
    expect(screen.getByText('hace 50 días')).toBeInTheDocument()
    expect(screen.getByText(/Historial disponible desde el 1 nov 2025/)).toBeInTheDocument()
    expect(screen.getByRole('table', { name: 'Productos principales' })).toHaveTextContent('Filtro de aceite')

    await u.click(await screen.findByRole('button', { name: 'Ver productos de S-N1' }))
    expect(await screen.findByText(/\(P1 · Filtros\)/)).toBeInTheDocument()
    expect(urls.some((x) => x.startsWith('/customers/document?') && x.includes('document_id=5'))).toBe(true)

    await u.click(screen.getByRole('button', { name: 'Ocultar productos de S-N1' }))
    expect(screen.queryByText(/\(P1 · Filtros\)/)).not.toBeInTheDocument()
  })

  it('avisa cuando el código tiene nombres distintos o varias compañías', async () => {
    respond = ((prev) => (path: string) =>
      path.startsWith('/customers/profile')
        ? { data: profile({
            name_variants: [{ name: 'Taller Pérez', documents: 2, last_seen: '2026-08-31' }, { name: 'TALLER PEREZ S.A.', documents: 1, last_seen: '2026-08-01' }],
            companies: [{ id: 1, name: 'Compañía Alfa', documents: 2 }, { id: 2, name: 'Compañía Beta', documents: 1 }],
          }) }
        : prev(path))(respond)
    renderProfile()

    expect(await screen.findByText(/2 nombres distintos/)).toHaveTextContent('No se unifican automáticamente')
    expect(screen.getByText(/Las cifras suman 2 compañías/)).toBeInTheDocument()
  })

  it('sin código no consulta', async () => {
    renderProfile('')
    expect(await screen.findByText(/Indica un cliente/)).toBeInTheDocument()
    expect(urls.filter((x) => x.startsWith('/customers'))).toEqual([])
  })
})
