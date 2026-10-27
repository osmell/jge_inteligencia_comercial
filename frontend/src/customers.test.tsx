import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { AuthProvider } from './auth/AuthContext'
import CustomerProfilePage from './pages/CustomerProfilePage'
import CustomerSalesReportPage from './pages/CustomerSalesReportPage'
import OperatorsPage from './pages/OperatorsPage'
import SellersPage from './pages/SellersPage'
import CustomerTypesPage from './pages/CustomerTypesPage'
import CustomersPage from './pages/CustomersPage'
import type { ContactPreference, CustomerInteraction, CustomerProfile } from './lib/customers'

const kpis = {
  net_total: '650.00', tax_total: '78.00', grand_total: '728.00', discount_total: '0.00', discount_pct: null, units: '8.00', documents: 3, customers: 1,
  average_ticket: '216.67', estimated_margin: '325.00', estimated_margin_pct: 50, cost_coverage_pct: 100,
}
const brow = (key: string, label: string, net: string) => ({ key, label, ...kpis, net_total: net })

const profile = (o: Partial<CustomerProfile> = {}): CustomerProfile => ({
  customer: {
    id: 1, company_id: 1, company: 'Compañía Alfa', branch: 'Central', code: 'C1', name: 'Taller Pérez S.A.', trade_name: 'Pérez Motors',
    contact_name: 'Pedro', phone: '5555-1234', whatsapp: null, email: 'taller@example.com', address: 'Zona 1', source: 'import', branch_id: 3,
    do_not_contact: false, do_not_contact_reason: null, type: { id: 1, name: 'Taller' }, abc: 'A',
  },
  activity: { status: 'inactive', inactive_days: 30, lost_days: 90 },
  assignment: { seller: { id: 8, name: 'Tienda Centro', assigned: false }, operator: { id: 4, name: 'Caja 1' }, supervisor: { id: 9, name: 'Sara' } },
  first_purchase: '2026-08-01', last_purchase: '2026-08-31', days_since_last_purchase: 50, history_starts_on: '2025-11-01', totals: kpis,
  frequency: { purchase_days: 3, average_days_between: 15 },
  monthly: [{ key: '2026-09', net_total: '0.00' }, { key: '2026-10', net_total: '650.00' }],
  top_products: [brow('P1', 'Filtro de aceite', '300.00')], top_categories: [brow('Filtros', 'Filtros', '300.00')],
  sellers: [brow('1', 'Ana', '350.00')], branches: [brow('1', 'Centro', '650.00')], ...o,
})

let urls: string[]
let calls: { path: string; method: string; body?: string }[]
let permissions: string[]
let respond: (path: string) => unknown
let interactions: CustomerInteraction[]
let preference: ContactPreference

beforeEach(() => {
  urls = []
  calls = []
  permissions = ['customers.view']
  preference = { do_not_contact: false, reason: null, since: null }
  interactions = [{
    id: 1, channel: 'call', direction: 'outbound', outcome: 'interested', occurred_at: '2026-10-10T15:30:00-06:00', response: 'Pidió precios de llantas',
    next_action: 'Enviar lista de precios', next_action_at: '2026-10-12T09:00:00-06:00', next_action_done_at: null, user: { id: 3, name: 'Vera' }, can_edit: true,
  }]
  document.cookie = 'XSRF-TOKEN=x'
  vi.stubGlobal('fetch', vi.fn(async (url: string, init?: RequestInit) => {
    const path = url.replace('http://localhost:8000/api/v1', '')
    urls.push(path)
    calls.push({ path, method: init?.method ?? 'GET', body: init?.body as string | undefined })
    return new Response(JSON.stringify(respond(path)), { status: 200 })
  }))
  respond = (path) => {
    if (path === '/auth/me') {
      return { data: { id: 1, username: 'gg', name: 'Gerencia', role: { slug: 'gerente_general', name: 'Gerente General' }, permissions, companies: [], branches: [] } }
    }
    if (path.startsWith('/customers/sales-report')) {
      return {
        data: [{ company_id: 1, company: 'Compañía Alfa', code: 'C1', name: 'Taller Pérez', type: 'Taller', assigned_seller: 'Ana', assigned_seller_is_default: false, supervisor: 'Sara', net_total: '150.00', documents: 2, last_purchase: '2026-10-03', direct_net_total: '50.00',
          sellers: [{ name: 'Ana', net_total: '100.00' }, { name: 'Luis', net_total: '50.00' }], sellers_more: 2, operators: [{ name: 'Caja 1', net_total: '100.00' }], operators_more: 0 }],
        meta: { page: 1, has_more: false },
      }
    }
    if (path.startsWith('/customers/sellers/')) return { data: {} }
    if (path.startsWith('/customers/sellers')) {
      return { data: [{ id: 7, name: 'Ana', company_id: 1, is_active: true, branch_id: null, branch: null, is_default: false, user_id: null, supervisor: null }, { id: 8, name: 'Tienda Centro', company_id: 1, is_active: true, branch_id: 3, branch: 'Centro', is_default: true, user_id: null, supervisor: { id: 9, name: 'Sara' } }] }
    }
    if (path.startsWith('/customers/operators')) return { data: [{ id: 4, name: 'Caja 1', company_id: 1, is_active: true }, { id: 5, name: 'Caja vieja', company_id: 1, is_active: false }] }
    if (path === '/customers/seller-users') return { data: [{ id: 20, name: 'Vera', username: 'vera' }] }
    if (path === '/customers/data') return { data: {} }
    if (path === '/customers/supervisors') return { data: [{ id: 9, name: 'Sara' }] }
    if (path === '/branches') return { data: [{ id: 3, company_id: 1, code: 'C', name: 'Centro', is_active: true }] }
    if (path === '/customers/assignment') return { data: {} }
    if (path === '/customers/activity-rules') return { data: { inactive_days: 30, lost_days: 90 } }
    if (path === '/customers/types') return { data: [{ id: 1, name: 'Taller', active: true }, { id: 2, name: 'Mayorista', active: false }] }
    if (path.startsWith('/customers/types/') || path === '/customers/type') return { data: {} }
    if (path.startsWith('/companies')) return { data: [{ id: 1, code: 'A', name: 'Compañía Alfa', is_active: true }] }
    if (/^\/customers\/\d+\/whatsapp$/.test(path)) return { data: { number: null, consent: null, blockers: [], ready: false, consents: [], templates: [], messages: [] } }
    if (path.startsWith('/customers/interactions')) return { data: interactions, meta: { page: 1, has_more: false, contact_preference: preference } }
    if (path.endsWith('/complete')) return { data: interactions[0] }
    if (path === '/customers/contact-preference') return { data: preference }
    if (path.startsWith('/customers/profile')) return { data: profile() }
    if (path.startsWith('/customers/purchases')) {
      return { data: [{ id: 5, document: 'S-N1', issued_on: '2026-08-01', branch: 'Centro', seller: 'Ana', operator: 'Caja 1', lines: 2, net_total: '150.00', grand_total: '168.00' }], meta: { page: 1, has_more: false } }
    }
    if (path.startsWith('/customers/document')) {
      return { data: [{ line: 1, product_code: 'P1', product_name: 'Filtro de aceite', category: 'Filtros', units: '2.00', net_total: '100.00', grand_total: '112.00' }] }
    }
    if (path.startsWith('/customers')) {
      return { data: [{ company_id: 1, company: 'Compañía Alfa', code: 'C1', name: 'Taller Pérez', trade_name: 'Pérez Motors', type: 'Taller', abc: 'A', seller: 'Tienda Centro', seller_is_default: true, activity: 'active', documents: 3, net_total: '650.00', first_purchase: '2026-08-01', last_purchase: '2026-08-31' }], meta: { page: 1, has_more: true } }
    }
    return { data: [] }
  }
})
afterEach(() => vi.unstubAllGlobals())

const renderProfile = (search = '?codigo=C1&compania=1') =>
  render(<AuthProvider><MemoryRouter initialEntries={[`/clientes/ficha${search}`]}><Routes><Route path="/clientes/ficha" element={<CustomerProfilePage />} /></Routes></MemoryRouter></AuthProvider>)

describe('listado de clientes', () => {
  it('lista, enlaza a la ficha y busca esperando a que termine de escribir', async () => {
    const u = userEvent.setup()
    render(<AuthProvider><MemoryRouter><CustomersPage /></MemoryRouter></AuthProvider>)

    const table = await screen.findByRole('table', { name: 'Clientes' })
    expect(within(table).getByRole('link', { name: 'Taller Pérez' })).toHaveAttribute('href', '/clientes/ficha?codigo=C1&compania=1')
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

    expect(await screen.findByRole('heading', { name: 'Taller Pérez S.A.' })).toBeInTheDocument()
    expect(screen.getByText('5555-1234')).toBeInTheDocument()
    expect(screen.getByText('Pérez Motors')).toBeInTheDocument()
    expect(screen.getByText('Venta neta total', { selector: 'p' }).closest('div')).toHaveTextContent('650.00')
    expect(screen.getByText('cada 15 días')).toBeInTheDocument()
    expect(screen.getByText('hace 50 días')).toBeInTheDocument()
    expect(screen.getByText(/Historial de ventas disponible desde el 1 nov 2025/)).toBeInTheDocument()
    expect(screen.getByRole('table', { name: 'Productos principales' })).toHaveTextContent('Filtro de aceite')

    await u.click(await screen.findByRole('button', { name: 'Ver productos de S-N1' }))
    expect(await screen.findByText(/\(P1 · Filtros\)/)).toBeInTheDocument()
    expect(urls.some((x) => x.startsWith('/customers/document?') && x.includes('document_id=5'))).toBe(true)

    await u.click(screen.getByRole('button', { name: 'Ocultar productos de S-N1' }))
    expect(screen.queryByText(/\(P1 · Filtros\)/)).not.toBeInTheDocument()
  })

  it('un cliente creado desde ventas o sin compras lo indica', async () => {
    respond = ((prev) => (path: string) =>
      path.startsWith('/customers/profile')
        ? { data: profile({ customer: { ...profile().customer, source: 'sales' }, last_purchase: null, first_purchase: null, days_since_last_purchase: null }) }
        : prev(path))(respond)
    renderProfile()

    expect(await screen.findByText(/se creó al aparecer en las ventas/)).toBeInTheDocument()
    expect(screen.getByText(/aún no tiene compras/)).toBeInTheDocument()
  })

  it('sin código o compañía no consulta', async () => {
    renderProfile('?codigo=C1')
    expect(await screen.findByText(/Indica un cliente/)).toBeInTheDocument()
    expect(urls.filter((x) => x.startsWith('/customers'))).toEqual([])
  })
})

describe('seguimiento comercial', () => {
  it('muestra el historial de contactos y solo ofrece registrar con permiso', async () => {
    renderProfile()

    const list = await screen.findByRole('list', { name: 'Contactos con el cliente' })
    expect(list).toHaveTextContent('Llamada')
    expect(list).toHaveTextContent('Interesado')
    expect(list).toHaveTextContent('Pidió precios de llantas')
    expect(list).toHaveTextContent('Próxima acción: Enviar lista de precios')
    expect(list).toHaveTextContent('Vera')
    expect(screen.queryByRole('button', { name: 'Registrar contacto' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Corregir contacto/ })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Marcar cumplida/ })).not.toBeInTheDocument()
  })

  it('marca como cumplida una próxima acción pendiente y muestra las cumplidas', async () => {
    permissions = ['customers.view', 'interactions.manage']
    interactions = [...interactions, { ...interactions[0], id: 2, next_action: 'Ya enviada', next_action_done_at: '2026-10-11T10:00:00-06:00' }]
    const u = userEvent.setup()
    renderProfile()

    const list = await screen.findByRole('list', { name: 'Contactos con el cliente' })
    expect(within(list).getAllByRole('button', { name: /Marcar cumplida/ })).toHaveLength(1)
    expect(list).toHaveTextContent('Cumplida')
    await u.click(within(list).getByRole('button', { name: /Marcar cumplida: Enviar lista de precios/ }))
    await waitFor(() => expect(calls.some((c) => c.path === '/customers/interactions/1/complete' && c.method === 'PUT')).toBe(true))
    expect(JSON.parse(calls.find((c) => c.path === '/customers/interactions/1/complete')?.body ?? '{}')).toEqual({ done: true })
  })

  it('registra un contacto con su próxima acción', async () => {
    permissions = ['customers.view', 'interactions.manage']
    const u = userEvent.setup()
    renderProfile()

    await u.click(await screen.findByRole('button', { name: 'Registrar contacto' }))
    await u.selectOptions(screen.getByLabelText('Canal'), 'visit')
    await u.selectOptions(screen.getByLabelText('Resultado'), 'quote_requested')
    await u.type(screen.getByLabelText('Respuesta del cliente / notas'), 'Quiere cotizar 4 llantas')
    await u.type(screen.getByLabelText('Próxima acción'), 'Preparar cotización')
    fireEvent.change(screen.getByLabelText('Fecha de la próxima acción'), { target: { value: '2026-10-20T09:00' } })
    await u.click(screen.getByRole('button', { name: 'Guardar' }))

    await waitFor(() => expect(calls.some((c) => c.path === '/customers/interactions' && c.method === 'POST')).toBe(true))
    const body = JSON.parse(calls.find((c) => c.path === '/customers/interactions' && c.method === 'POST')?.body ?? '{}')
    expect(body).toMatchObject({ company_id: 1, code: 'C1', channel: 'visit', direction: 'outbound', outcome: 'quote_requested', response: 'Quiere cotizar 4 llantas', next_action: 'Preparar cotización', next_action_at: '2026-10-20T09:00' })
    await waitFor(() => expect(screen.queryByRole('button', { name: 'Guardar' })).not.toBeInTheDocument())
  })

  it('corrige un contacto propio', async () => {
    permissions = ['customers.view', 'interactions.manage']
    const u = userEvent.setup()
    renderProfile()

    await u.click(await screen.findByRole('button', { name: /Corregir contacto del/ }))
    const notes = screen.getByLabelText('Respuesta del cliente / notas')
    expect(notes).toHaveValue('Pidió precios de llantas')
    await u.clear(notes)
    await u.type(notes, 'Corregido')
    await u.click(screen.getByRole('button', { name: 'Guardar' }))

    await waitFor(() => expect(calls.some((c) => c.path === '/customers/interactions/1' && c.method === 'PUT')).toBe(true))
    expect(JSON.parse(calls.find((c) => c.method === 'PUT' && c.path === '/customers/interactions/1')?.body ?? '{}')).toMatchObject({ response: 'Corregido' })
  })

  it('con «no contactar» avisa, solo permite contactos del cliente y se puede retirar', async () => {
    permissions = ['customers.view', 'interactions.manage']
    preference = { do_not_contact: true, reason: 'Lo pidió por correo', since: '2026-10-01T10:00:00-06:00' }
    const u = userEvent.setup()
    renderProfile()

    expect(await screen.findByText(/pidió no recibir comunicaciones: Lo pidió por correo/)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Registrar contacto' })).not.toBeInTheDocument()
    await u.click(screen.getByRole('button', { name: 'Registrar contacto del cliente' }))
    expect(screen.getByLabelText('Quién inició')).toHaveValue('inbound')
    expect(screen.queryByLabelText('Próxima acción')).not.toBeInTheDocument()
    await u.click(screen.getByRole('button', { name: 'Cancelar' }))

    await u.click(screen.getByRole('button', { name: 'El cliente volvió a aceptar comunicaciones' }))
    await waitFor(() => expect(calls.some((c) => c.path === '/customers/contact-preference' && c.method === 'PUT')).toBe(true))
    expect(JSON.parse(calls.find((c) => c.path === '/customers/contact-preference')?.body ?? '{}')).toMatchObject({ do_not_contact: false })
  })

  it('registra que el cliente pidió no ser contactado, con motivo obligatorio', async () => {
    permissions = ['customers.view', 'interactions.manage']
    const u = userEvent.setup()
    renderProfile()

    await u.click(await screen.findByRole('button', { name: 'El cliente pidió no ser contactado' }))
    expect(screen.getByRole('button', { name: 'Registrar' })).toBeDisabled()
    await u.type(screen.getByLabelText('Motivo o cómo lo pidió el cliente'), 'Llamó a pedirlo')
    await u.click(screen.getByRole('button', { name: 'Registrar' }))

    await waitFor(() => expect(calls.some((c) => c.path === '/customers/contact-preference')).toBe(true))
    expect(JSON.parse(calls.find((c) => c.path === '/customers/contact-preference')?.body ?? '{}')).toMatchObject({ do_not_contact: true, reason: 'Llamó a pedirlo' })
  })
})

describe('tipos de cliente', () => {
  it('el listado muestra tipo y clase ABC y filtra por tipo', async () => {
    const u = userEvent.setup()
    render(<AuthProvider><MemoryRouter><CustomersPage /></MemoryRouter></AuthProvider>)

    const table = await screen.findByRole('table', { name: 'Clientes' })
    expect(within(table).getByText('Taller')).toBeInTheDocument()
    expect(within(table).getByText('A')).toBeInTheDocument()
    expect(within(table).getByText('Activo')).toBeInTheDocument()
    expect(screen.queryByRole('link', { name: 'Tipos de cliente' })).not.toBeInTheDocument()

    await u.selectOptions(await screen.findByLabelText('Tipo'), '1')
    await waitFor(() => expect(urls.at(-1)).toContain('type_id=1'))
    await u.selectOptions(screen.getByLabelText('Estado'), 'lost')
    await waitFor(() => expect(urls.at(-1)).toContain('status=lost'))
  })

  it('con permiso de gestión, la ficha cambia el tipo y lo guarda', async () => {
    permissions = ['customers.view', 'customers.manage']
    const u = userEvent.setup()
    renderProfile()

    const select = await screen.findByLabelText('Cambiar tipo')
    expect(within(select).queryByRole('option', { name: 'Mayorista' })).not.toBeInTheDocument() // inactivo
    await u.selectOptions(select, '')
    await waitFor(() => expect(calls.some((c) => c.method === 'PUT' && c.path === '/customers/type')).toBe(true))
    expect(JSON.parse(calls.find((c) => c.method === 'PUT')!.body!)).toEqual({ company_id: 1, code: 'C1', customer_type_id: null })
  })

  it('sin permiso de gestión la ficha no ofrece cambiar el tipo', async () => {
    renderProfile()
    await screen.findByRole('heading', { name: 'Taller Pérez S.A.' })
    expect(screen.queryByLabelText('Cambiar tipo')).not.toBeInTheDocument()
    expect(screen.getByText('Clasificación ABC').closest('div')).toHaveTextContent('Clase A')
  })

  it('el catálogo permite crear, renombrar y desactivar', async () => {
    permissions = ['customers.view', 'customers.manage']
    const u = userEvent.setup()
    render(<AuthProvider><MemoryRouter><CustomerTypesPage /></MemoryRouter></AuthProvider>)

    const table = await screen.findByRole('table', { name: 'Tipos de cliente' })
    expect(within(table).getByText('Inactivo')).toBeInTheDocument()

    await u.type(screen.getByLabelText('Nuevo tipo'), 'Ferretería')
    await u.click(screen.getByRole('button', { name: 'Agregar tipo' }))
    await waitFor(() => expect(calls.some((c) => c.method === 'POST' && c.path === '/customers/types')).toBe(true))
    expect(JSON.parse(calls.find((c) => c.method === 'POST')!.body!)).toEqual({ name: 'Ferretería' })

    await u.click(screen.getByRole('button', { name: 'Desactivar Taller' }))
    await waitFor(() => expect(calls.some((c) => c.method === 'PUT' && c.path === '/customers/types/1')).toBe(true))
    expect(JSON.parse(calls.find((c) => c.path === '/customers/types/1')!.body!)).toEqual({ active: false })

    await u.click(screen.getByRole('button', { name: 'Renombrar Taller' }))
    const input = screen.getByLabelText('Nombre de Taller')
    await u.clear(input)
    await u.type(input, 'Taller mecánico')
    await u.click(screen.getByRole('button', { name: 'Guardar' }))
    await waitFor(() => expect(calls.filter((c) => c.path === '/customers/types/1').length).toBe(2))
    expect(JSON.parse(calls.filter((c) => c.path === '/customers/types/1')[1].body!)).toEqual({ name: 'Taller mecánico' })
  })

  it('las reglas de actividad se editan y se envían', async () => {
    permissions = ['customers.view', 'customers.manage']
    const u = userEvent.setup()
    render(<AuthProvider><MemoryRouter><CustomerTypesPage /></MemoryRouter></AuthProvider>)

    const inactive = await screen.findByLabelText('Días para inactivo')
    await waitFor(() => expect(inactive).toHaveValue(30))
    await u.clear(inactive)
    await u.type(inactive, '45')
    await u.click(screen.getByRole('button', { name: 'Guardar reglas' }))
    await waitFor(() => expect(calls.some((c) => c.method === 'PUT' && c.path === '/customers/activity-rules')).toBe(true))
    expect(JSON.parse(calls.find((c) => c.path === '/customers/activity-rules' && c.method === 'PUT')!.body!)).toEqual({ inactive_days: 45, lost_days: 90 })
  })
})

describe('asignaciones', () => {
  it('la ficha muestra vendedor (tienda por defecto), facturador y supervisor', async () => {
    renderProfile()
    await screen.findByRole('heading', { name: 'Taller Pérez S.A.' })
    expect(screen.getByText('Vendedor', { selector: 'dt' }).closest('div')).toHaveTextContent('Tienda Centro (tienda por defecto)')
    expect(screen.getByText('Facturador', { selector: 'dt' }).closest('div')).toHaveTextContent('Caja 1')
    expect(screen.getByText('Supervisor', { selector: 'dt' }).closest('div')).toHaveTextContent('Sara')
  })

  it('con permiso de gestión asigna el vendedor conservando el facturador', async () => {
    permissions = ['customers.view', 'customers.manage']
    const u = userEvent.setup()
    renderProfile()

    const select = await screen.findByLabelText('Asignar vendedor')
    await screen.findByRole('option', { name: 'Ana' })
    await u.selectOptions(select, '7')
    await waitFor(() => expect(calls.some((c) => c.method === 'PUT' && c.path === '/customers/assignment')).toBe(true))
    expect(JSON.parse(calls.find((c) => c.path === '/customers/assignment' && c.method === 'PUT')!.body!)).toEqual({ company_id: 1, code: 'C1', seller_id: 7, operator_id: 4 })
  })

  it('el catálogo de vendedores marca la tienda por defecto y asigna supervisor', async () => {
    permissions = ['customers.view', 'customers.manage']
    const u = userEvent.setup()
    render(<AuthProvider><MemoryRouter><SellersPage /></MemoryRouter></AuthProvider>)

    const table = await screen.findByRole('table', { name: 'Vendedores' })
    expect(within(table).getByLabelText('Ana es tienda por defecto')).toBeDisabled() // sin sucursal no puede serlo
    expect(within(table).getByLabelText('Tienda Centro es tienda por defecto')).toBeChecked()

    await u.selectOptions(within(table).getByLabelText('Supervisor de Ana'), '9')
    await waitFor(() => expect(calls.some((c) => c.method === 'PUT' && c.path === '/customers/sellers/7')).toBe(true))
    expect(JSON.parse(calls.find((c) => c.path === '/customers/sellers/7')!.body!)).toEqual({ supervisor_id: 9 })
  })
})

describe('reporte de ventas por cliente', () => {
  it('muestra quién vendió y quién facturó, y consulta con el periodo y filtros', async () => {
    const u = userEvent.setup()
    render(<AuthProvider><MemoryRouter><CustomerSalesReportPage /></MemoryRouter></AuthProvider>)

    const table = await screen.findByRole('table', { name: 'Ventas por cliente' })
    expect(within(table).getByRole('link', { name: 'Taller Pérez' })).toBeInTheDocument()
    expect(within(table).getByText('Luis')).toBeInTheDocument()
    expect(within(table).getByText('Caja 1')).toBeInTheDocument()
    expect(within(table).getByText(/y 2 más/)).toBeInTheDocument()
    expect(urls.some((x) => /^\/customers\/sales-report\?from=\d{4}-\d{2}-01&to=\d{4}-\d{2}-\d{2}&page=1$/.test(x))).toBe(true)

    await u.selectOptions(screen.getByLabelText('Compañía'), '1')
    await waitFor(() => expect(urls.at(-1)).toContain('company_id=1'))
  })

  it('con un periodo inválido no consulta y lo explica', async () => {
    render(<AuthProvider><MemoryRouter><CustomerSalesReportPage /></MemoryRouter></AuthProvider>)
    await screen.findByRole('table', { name: 'Ventas por cliente' })
    const before = urls.filter((x) => x.startsWith('/customers/sales-report')).length
    fireEvent.change(screen.getByLabelText('Desde'), { target: { value: '2999-01-01' } })
    expect(await screen.findByText(/periodo válido/)).toBeInTheDocument()
    expect(urls.filter((x) => x.startsWith('/customers/sales-report')).length).toBe(before)
  })
})

describe('mantenimiento', () => {
  it('la ficha permite editar los datos generales y envía el cambio', async () => {
    permissions = ['customers.view', 'customers.manage']
    const u = userEvent.setup()
    renderProfile()

    await u.click(await screen.findByRole('button', { name: 'Editar datos' }))
    const name = screen.getByLabelText('Nombre')
    expect(name).toHaveValue('Taller Pérez S.A.')
    await u.clear(name)
    await u.type(name, 'Taller Pérez y Cía')
    await u.clear(screen.getByLabelText('Teléfono'))
    await u.click(screen.getByRole('button', { name: 'Guardar cambios' }))

    await waitFor(() => expect(calls.some((c) => c.method === 'PUT' && c.path === '/customers/data')).toBe(true))
    expect(JSON.parse(calls.find((c) => c.path === '/customers/data')!.body!)).toMatchObject({ company_id: 1, code: 'C1', name: 'Taller Pérez y Cía', phone: '', branch_id: 3 })
  })

  it('sin permiso de gestión la ficha no ofrece editar y no ofrece facturadores inactivos', async () => {
    renderProfile()
    await screen.findByRole('heading', { name: 'Taller Pérez S.A.' })
    expect(screen.queryByRole('button', { name: 'Editar datos' })).not.toBeInTheDocument()
  })

  it('el catálogo de vendedores crea, renombra, vincula usuario y desactiva', async () => {
    permissions = ['customers.view', 'customers.manage']
    const u = userEvent.setup()
    render(<AuthProvider><MemoryRouter><SellersPage /></MemoryRouter></AuthProvider>)

    const table = await screen.findByRole('table', { name: 'Vendedores' })
    await u.type(screen.getByLabelText('Nuevo vendedor'), 'Beto Ríos')
    await screen.findByRole('option', { name: 'Compañía Alfa' })
    await u.selectOptions(screen.getByLabelText('Compañía'), '1')
    await u.click(screen.getByRole('button', { name: 'Agregar vendedor' }))
    await waitFor(() => expect(calls.some((c) => c.method === 'POST' && c.path === '/customers/sellers')).toBe(true))
    expect(JSON.parse(calls.find((c) => c.method === 'POST')!.body!)).toEqual({ company_id: 1, name: 'Beto Ríos' })

    await screen.findAllByRole('option', { name: 'Vera (vera)' })
    await u.selectOptions(within(table).getByLabelText('Usuario de Ana'), '20')
    await waitFor(() => expect(calls.some((c) => c.path === '/customers/sellers/7' && c.method === 'PUT')).toBe(true))
    expect(JSON.parse(calls.find((c) => c.path === '/customers/sellers/7')!.body!)).toEqual({ user_id: 20 })

    await u.click(within(table).getByLabelText('Ana está activo'))
    await waitFor(() => expect(calls.filter((c) => c.path === '/customers/sellers/7').length).toBe(2))
    expect(JSON.parse(calls.filter((c) => c.path === '/customers/sellers/7')[1].body!)).toEqual({ is_active: false })

    await u.click(within(table).getByRole('button', { name: 'Renombrar Ana' }))
    const input = within(table).getByLabelText('Nombre de Ana')
    await u.clear(input)
    await u.type(input, 'Ana Torres')
    await u.click(within(table).getByRole('button', { name: 'Guardar' }))
    await waitFor(() => expect(calls.filter((c) => c.path === '/customers/sellers/7').length).toBe(3))
    expect(JSON.parse(calls.filter((c) => c.path === '/customers/sellers/7')[2].body!)).toEqual({ name: 'Ana Torres' })
  })

  it('el catálogo de facturadores crea, renombra y desactiva', async () => {
    permissions = ['customers.view', 'customers.manage']
    const u = userEvent.setup()
    render(<AuthProvider><MemoryRouter><OperatorsPage /></MemoryRouter></AuthProvider>)

    const table = await screen.findByRole('table', { name: 'Facturadores' })
    expect(within(table).getByLabelText('Caja vieja está activo')).not.toBeChecked()

    await screen.findByRole('option', { name: 'Compañía Alfa' })
    await u.selectOptions(screen.getByLabelText('Compañía'), '1')
    await u.type(screen.getByLabelText('Nuevo facturador'), 'Caja 2')
    await u.click(screen.getByRole('button', { name: 'Agregar facturador' }))
    await waitFor(() => expect(calls.some((c) => c.method === 'POST' && c.path === '/customers/operators')).toBe(true))
    expect(JSON.parse(calls.find((c) => c.method === 'POST')!.body!)).toEqual({ company_id: 1, name: 'Caja 2' })

    await u.click(within(table).getByLabelText('Caja vieja está activo'))
    await waitFor(() => expect(calls.some((c) => c.path === '/customers/operators/5' && c.method === 'PUT')).toBe(true))
    expect(JSON.parse(calls.find((c) => c.path === '/customers/operators/5')!.body!)).toEqual({ is_active: true })
  })
})
