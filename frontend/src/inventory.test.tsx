import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { AuthProvider } from './auth/AuthContext'
import InventoryUnitsPage from './pages/InventoryUnitsPage'

let permissions: string[]
let puts: { path: string; body: Record<string, unknown> }[]

const units = [
  { id: 1, code: 'galon', name: 'Galón', active: true },
  { id: 2, code: 'cubeta', name: 'Cubeta', active: true },
  { id: 3, code: '1_16', name: '1/16', active: true },
]

beforeEach(() => {
  permissions = ['inventory.view', 'inventory.manage']
  puts = []
  document.cookie = 'XSRF-TOKEN=x'
  vi.stubGlobal('fetch', vi.fn(async (url: string, init?: RequestInit) => {
    const path = url.replace('http://localhost:8000/api/v1', '')
    if (init?.method === 'PUT') {
      puts.push({ path, body: JSON.parse(String(init.body)) })
      return new Response(JSON.stringify({ data: {} }), { status: 200 })
    }
    let body: unknown = { data: [] }
    if (path === '/auth/me') body = { data: { id: 1, username: 'a', name: 'Ana', role: { slug: 'administrador', name: 'Administrador' }, permissions, companies: [], branches: [] } }
    else if (path === '/inventory/units') body = { data: units }
    else if (path === '/inventory/products/7/units') body = { data: { id: 7, company_id: 1, code: 'ACE-1', name: 'Aceite 20W50', base_unit: { id: 1, code: 'galon', name: 'Galón' }, equivalences: [{ unit_id: 2, unit: 'Cubeta', factor: '5' }] } }
    else if (path.startsWith('/inventory/products')) {
      body = { data: [{ id: 7, company_id: 1, code: 'ACE-1', name: 'Aceite 20W50', base_unit: { id: 1, code: 'galon', name: 'Galón' }, equivalences_count: 1 }, { id: 8, company_id: 1, code: 'FIL-9', name: 'Filtro', base_unit: null, equivalences_count: 0 }] }
    }
    return new Response(JSON.stringify(body), { status: 200 })
  }))
})
afterEach(() => vi.unstubAllGlobals())

const renderPage = () => render(<AuthProvider><MemoryRouter><InventoryUnitsPage /></MemoryRouter></AuthProvider>)

describe('unidades y equivalencias', () => {
  it('lista las unidades y los productos con su unidad base', async () => {
    renderPage()

    const units = await screen.findByRole('table', { name: 'Unidades de medida' })
    expect(units).toHaveTextContent('Cubeta')
    const products = await screen.findByRole('table', { name: 'Productos y unidad base' })
    await waitFor(() => expect(products).toHaveTextContent('Aceite 20W50'))
    expect(products).toHaveTextContent('Sin definir')
  })

  it('edita la unidad base y las equivalencias de un producto', async () => {
    const u = userEvent.setup()
    renderPage()

    await u.click(await screen.findByRole('button', { name: 'Aceite 20W50' }))
    const factor = await screen.findByLabelText('Vale (Galón)')
    expect(factor).toHaveValue('5')
    await u.clear(factor)
    await u.type(factor, '5.5')
    await u.click(screen.getByRole('button', { name: 'Agregar equivalencia' }))
    await u.selectOptions(screen.getByLabelText('Unidad', { selector: '#eq-unit-1' }), '3')
    await u.type(screen.getByLabelText('Vale (Galón)', { selector: '#eq-factor-1' }), '0.0625')
    await u.click(screen.getByRole('button', { name: 'Guardar' }))

    await waitFor(() => expect(puts).toHaveLength(1))
    expect(puts[0].path).toBe('/inventory/products/7/units')
    expect(puts[0].body).toEqual({ base_unit_id: 1, equivalences: [{ unit_id: 2, factor: '5.5' }, { unit_id: 3, factor: '0.0625' }] })
  })

  it('con solo consulta no ofrece editar', async () => {
    permissions = ['inventory.view']
    const u = userEvent.setup()
    renderPage()

    await u.click(await screen.findByRole('button', { name: 'Aceite 20W50' }))
    await screen.findByLabelText('Vale (Galón)')
    expect(screen.queryByRole('button', { name: 'Agregar equivalencia' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Guardar' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Agregar unidad' })).not.toBeInTheDocument()
  })
})
