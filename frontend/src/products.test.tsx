import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { AuthProvider } from './auth/AuthContext'
import ProductsPage from './pages/ProductsPage'

let urls: string[]
let missingCount: number

beforeEach(() => {
  urls = []
  missingCount = 2
  vi.stubGlobal('fetch', vi.fn(async (url: string) => {
    const path = url.replace('http://localhost:8000/api/v1', '')
    urls.push(path)
    const json = (b: unknown) => new Response(JSON.stringify(b), { status: 200 })
    if (path === '/auth/me') return json({ data: { id: 1, username: 'gg', name: 'G', role: { slug: 'gerente_general', name: 'Gerente' }, permissions: ['sales.view', 'imports.manage'], companies: [], branches: [] } })
    if (path.startsWith('/companies')) return json({ data: [{ id: 1, code: 'A', name: 'Compañía Alfa', is_active: true }] })
    if (path.startsWith('/sales/products/coverage')) {
      return json({ data: { catalog_products: 120, sold_products: 90, missing_products: missingCount, net_total: '8000.00', missing_net_total: '300.00', covered_pct: 96.25 } })
    }
    if (path.startsWith('/sales/products/levels')) return json({ data: { level1: ['Repuestos'], level2: ['Motor'], level3: ['Filtros'] } })
    if (path.startsWith('/sales/products/missing')) {
      return json({ data: [{ company_id: 1, company: 'Compañía Alfa', code: '999', name: 'Producto nuevo', category: 'Varios', net_total: '250.00' }], meta: { page: 1, has_more: false } })
    }
    if (path.startsWith('/sales/products')) {
      return json({ data: [{ company_id: 1, company: 'Compañía Alfa', code: '100', name: 'Filtro de aceite', level1: 'Repuestos', level2: 'Motor', level3: null, net_total: '500.00' }], meta: { page: 1, has_more: true } })
    }
    return json({ data: [] })
  }))
})
afterEach(() => vi.unstubAllGlobals())

const renderPage = () => render(<AuthProvider><MemoryRouter><ProductsPage /></MemoryRouter></AuthProvider>)

describe('catálogo de productos', () => {
  it('muestra la cobertura, el catálogo con sus niveles y filtra por nivel y texto', async () => {
    const u = userEvent.setup()
    renderPage()

    const table = await screen.findByRole('table', { name: 'Catálogo de productos' })
    expect(within(table).getByText('Filtro de aceite')).toBeInTheDocument()
    expect(within(table).getByText('Repuestos')).toBeInTheDocument()
    expect(screen.getByText('Venta con catálogo').closest('div')).toHaveTextContent('96.25 %')
    expect(screen.getByText(/2 productos vendidos no están en el catálogo/)).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Cargar catálogo' })).toHaveAttribute('href', '/importaciones')

    await screen.findByRole('option', { name: 'Motor' })
    await u.selectOptions(screen.getByLabelText('Línea (Nivel 2)'), 'Motor')
    await waitFor(() => expect(urls.at(-1)).toContain('level2=Motor'))
    await u.selectOptions(screen.getByLabelText('Categoría (Nivel 3)'), '__none__')
    await waitFor(() => expect(urls.at(-1)).toContain('level3=__none__'))

    await u.type(screen.getByLabelText('Buscar por código o nombre'), 'filtro')
    await waitFor(() => expect(urls.at(-1)).toContain('q=filtro'))
    await u.click(screen.getByRole('button', { name: 'Siguiente' }))
    await waitFor(() => expect(urls.at(-1)).toContain('page=2'))
  })

  it('lista los productos vendidos que no están en el catálogo', async () => {
    const u = userEvent.setup()
    renderPage()
    await u.click(await screen.findByRole('tab', { name: 'Vendidos sin catálogo' }))

    const table = await screen.findByRole('table', { name: 'Productos vendidos sin catálogo' })
    expect(within(table).getByText('Producto nuevo')).toBeInTheDocument()
    expect(within(table).getByText('Varios')).toBeInTheDocument()
    expect(urls.some((x) => x.startsWith('/sales/products/missing?page=1'))).toBe(true)
  })

  it('sin productos sin catálogo no muestra el aviso', async () => {
    missingCount = 0
    renderPage()
    await screen.findByRole('table', { name: 'Catálogo de productos' })
    await screen.findByText('Venta con catálogo')
    expect(screen.queryByText(/no están en el catálogo/)).not.toBeInTheDocument()
  })
})
