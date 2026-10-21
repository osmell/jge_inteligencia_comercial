import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { AuthProvider } from './auth/AuthContext'
import type { AuthUser } from './auth/types'
import AuditPage from './pages/AuditPage'
import BranchesPage from './pages/BranchesPage'
import CompaniesPage from './pages/CompaniesPage'
import RolesPage from './pages/RolesPage'
import UsersPage from './pages/UsersPage'

const admin: AuthUser = {
  id: 1, username: 'admin', name: 'Ana Admin', role: { slug: 'administrador', name: 'Administrador' },
  permissions: ['companies.manage', 'branches.manage', 'users.manage', 'roles.manage', 'audit.view'], companies: [], branches: [],
}
const companies = [{ id: 1, code: 'ALFA', name: 'Alfa', is_active: true }, { id: 2, code: 'BETA', name: 'Beta', is_active: false }]
const branches = [
  { id: 10, company_id: 1, code: 'CEN', name: 'Alfa Central', is_active: true },
  { id: 11, company_id: 1, code: 'NOR', name: 'Alfa Norte', is_active: true },
]
const roles = [
  { id: 1, slug: 'administrador', name: 'Administrador' },
  { id: 4, slug: 'vendedor', name: 'Vendedor' },
]
const users = [
  { id: 1, username: 'admin', name: 'Ana Admin', email: null, is_active: true, last_login_at: null, role: roles[0], company_ids: [], branch_ids: [] },
  { id: 7, username: 'vera', name: 'Vera', email: null, is_active: true, last_login_at: null, role: roles[1], company_ids: [1], branch_ids: [10] },
]

const matrix = {
  permissions: [
    { slug: 'companies.manage', name: 'Compañías', admin_only: false },
    { slug: 'users.manage', name: 'Usuarios', admin_only: false },
    { slug: 'roles.manage', name: 'Roles y permisos', admin_only: true },
  ],
  roles: [
    { id: 1, slug: 'administrador', name: 'Administrador', locked: true, permissions: ['companies.manage', 'users.manage', 'roles.manage'] },
    { id: 2, slug: 'gerente_general', name: 'Gerente General', locked: false, permissions: ['companies.manage'] },
  ],
}

type Call = { url: string; method: string; body: unknown }
let calls: Call[]
let overrides: Record<string, () => Response>

function mockApi() {
  calls = []
  vi.stubGlobal('fetch', vi.fn(async (url: string, init?: RequestInit) => {
    const path = url.replace('http://localhost:8000/api/v1', '')
    const method = init?.method ?? 'GET'
    calls.push({ url: path, method, body: init?.body ? JSON.parse(init.body as string) : undefined })
    const key = `${method} ${path}`
    if (overrides[key]) return overrides[key]()
    const json = (b: unknown, status = 200) => new Response(JSON.stringify(b), { status })
    if (path === '/auth/me') return json({ data: admin })
    if (path === '/admin/companies' && method === 'GET') return json({ data: companies })
    if (path === '/admin/branches' && method === 'GET') return json({ data: branches })
    if (path === '/companies') return json({ data: companies })
    if (path === '/admin/role-permissions') return json({ data: matrix })
    if (path === '/admin/roles') return json({ data: roles })
    if (path === '/admin/users' && method === 'GET') return json({ data: users })
    if (path.startsWith('/admin/audit-logs')) {
      return json({ data: [{ id: 1, username: 'admin', event: 'auth.login', auditable_type: 'App\\Models\\User', auditable_id: 1, company_id: null, ip_address: '1.1.1.1', old_values: null, new_values: { x: 1 }, created_at: '2026-10-19T10:00:00Z' }], current_page: 1, last_page: 3, total: 120 })
    }
    return json({ data: {} }, 201)
  }))
}

function renderPage(page: React.ReactNode) {
  return render(<MemoryRouter><AuthProvider>{page}</AuthProvider></MemoryRouter>)
}

describe('pantallas administrativas', () => {
  beforeEach(() => {
    overrides = {}
    document.cookie = 'XSRF-TOKEN=token; path=/'
    mockApi()
  })
  afterEach(() => vi.unstubAllGlobals())

  it('lista compañías con su estado en texto', async () => {
    renderPage(<CompaniesPage />)

    const table = await screen.findByRole('table', { name: 'Compañías' })
    expect(within(table).getByText('Inactivo')).toBeInTheDocument()
    expect(screen.queryByText('Alfa Central')).not.toBeInTheDocument()
  })

  it('crea una compañía y muestra errores de validación por campo', async () => {
    overrides['POST /admin/companies'] = () => new Response(JSON.stringify({ message: 'x', errors: { code: ['El código ya existe.'] } }), { status: 422 })
    renderPage(<CompaniesPage />)

    await userEvent.click(await screen.findByRole('button', { name: 'Nueva compañía' }))
    await userEvent.type(screen.getByLabelText('Código'), 'ALFA')
    await userEvent.type(screen.getByLabelText('Nombre'), 'Otra')
    await userEvent.click(screen.getByRole('button', { name: 'Guardar' }))

    expect(await screen.findByText('Error: El código ya existe.')).toBeInTheDocument()
    expect(calls.find((c) => c.method === 'POST')?.body).toEqual({ code: 'ALFA', name: 'Otra', is_active: true })
  })

  it('lista sucursales en su propia pantalla y usa el catálogo de compañías visibles', async () => {
    renderPage(<BranchesPage />)

    expect(await screen.findByText('Alfa Central')).toBeInTheDocument()
    expect(calls.some((c) => c.url === '/companies')).toBe(true)
    expect(calls.some((c) => c.url === '/admin/companies')).toBe(false)
  })

  it('si la sucursal tiene datos relacionados la compañía no se puede cambiar y se explica por qué', async () => {
    overrides['GET /admin/branches/10/relocation'] = () => new Response(JSON.stringify({ data: { can_move: false, blockers: [{ label: 'clientes', count: 12 }, { label: 'documentos de venta', count: 340 }] } }))
    renderPage(<BranchesPage />)

    await userEvent.click(await screen.findByRole('button', { name: 'Editar sucursal Alfa Central' }))
    expect(await screen.findByText(/12 clientes, 340 documentos de venta/)).toBeInTheDocument()
    expect(screen.getByLabelText('Compañía')).toBeDisabled()
    await userEvent.click(screen.getByRole('button', { name: 'Guardar' }))

    const put = calls.find((c) => c.method === 'PUT')
    expect(put?.url).toBe('/admin/branches/10')
    expect(put?.body).not.toHaveProperty('company_id')
  })

  it('una sucursal sin datos se puede mover a otra compañía y se envía la nueva', async () => {
    overrides['GET /admin/branches/10/relocation'] = () => new Response(JSON.stringify({ data: { can_move: true, blockers: [] } }))
    renderPage(<BranchesPage />)

    await userEvent.click(await screen.findByRole('button', { name: 'Editar sucursal Alfa Central' }))
    expect(await screen.findByText(/no tiene datos relacionados: puedes moverla/)).toBeInTheDocument()
    await waitFor(() => expect(screen.getByLabelText('Compañía')).toBeEnabled())
    await userEvent.selectOptions(screen.getByLabelText('Compañía'), 'Beta')
    await userEvent.click(screen.getByRole('button', { name: 'Guardar' }))

    await waitFor(() => expect(calls.find((c) => c.method === 'PUT')?.body).toMatchObject({ company_id: 2, code: 'CEN' }))
  })

  it('si el servidor rechaza el cambio de compañía muestra su mensaje', async () => {
    overrides['GET /admin/branches/10/relocation'] = () => new Response(JSON.stringify({ data: { can_move: true, blockers: [] } }))
    overrides['PUT /admin/branches/10'] = () => new Response(JSON.stringify({ message: 'x', errors: { company_id: ['No se puede mover la sucursal a otra compañía porque tiene datos relacionados: 1 cliente.'] } }), { status: 422 })
    renderPage(<BranchesPage />)

    await userEvent.click(await screen.findByRole('button', { name: 'Editar sucursal Alfa Central' }))
    await waitFor(() => expect(screen.getByLabelText('Compañía')).toBeEnabled())
    await userEvent.selectOptions(screen.getByLabelText('Compañía'), 'Beta')
    await userEvent.click(screen.getByRole('button', { name: 'Guardar' }))

    expect(await screen.findByText(/porque tiene datos relacionados: 1 cliente/)).toBeInTheDocument()
  })

  it('la matriz de permisos bloquea al Administrador y el permiso solo-administrador', async () => {
    renderPage(<RolesPage />)

    expect(await screen.findByLabelText('Administrador: Compañías')).toBeDisabled()
    expect(screen.getByLabelText('Administrador: Compañías')).toBeChecked()
    expect(screen.getByLabelText('Gerente General: Roles y permisos')).toBeDisabled()
    expect(screen.getByLabelText('Gerente General: Compañías')).toBeChecked()
    expect(screen.getByRole('button', { name: 'Guardar cambios' })).toBeDisabled()
  })

  it('otorga un permiso a un rol y guarda solo el rol modificado', async () => {
    renderPage(<RolesPage />)

    await userEvent.click(await screen.findByLabelText('Gerente General: Usuarios'))
    await userEvent.click(screen.getByRole('button', { name: 'Guardar cambios' }))

    const puts = calls.filter((c) => c.method === 'PUT')
    expect(puts).toHaveLength(1)
    expect(puts[0].url).toBe('/admin/role-permissions/2')
    expect(puts[0].body).toEqual({ permissions: ['companies.manage', 'users.manage'] })
  })

  it('crea un usuario con ámbito y quita sus sucursales al desmarcar la compañía', async () => {
    renderPage(<UsersPage />)

    await userEvent.click(await screen.findByRole('button', { name: 'Nuevo usuario' }))
    await userEvent.type(screen.getByLabelText('Nombre de usuario'), 'nuevo')
    await userEvent.type(screen.getByLabelText('Nombre completo'), 'Nuevo Usuario')
    await userEvent.type(screen.getByLabelText('Contraseña'), 'Clave-segura-123')
    await userEvent.click(screen.getByLabelText('Alfa'))
    await userEvent.click(screen.getByLabelText('Sucursal Alfa Central'))
    await userEvent.click(screen.getByLabelText('Alfa')) // desmarcar: limpia sucursal
    await userEvent.click(screen.getByLabelText('Alfa'))
    await userEvent.click(screen.getByLabelText('Sucursal Alfa Norte'))
    await userEvent.click(screen.getByRole('button', { name: 'Guardar' }))

    expect(calls.find((c) => c.method === 'POST')?.body).toEqual({
      username: 'nuevo', name: 'Nuevo Usuario', email: null, role_id: 4, is_active: true,
      company_ids: [1], branch_ids: [11], password: 'Clave-segura-123',
    })
  })

  it('el administrador no puede cambiar su propio rol ni desactivarse', async () => {
    renderPage(<UsersPage />)

    await userEvent.click(await screen.findByRole('button', { name: 'Editar usuario admin' }))

    expect(screen.getByLabelText('Rol')).toBeDisabled()
    expect(screen.getByLabelText('Usuario activo')).toBeDisabled()
  })

  it('al editar sin escribir contraseña no la envía', async () => {
    renderPage(<UsersPage />)

    await userEvent.click(await screen.findByRole('button', { name: 'Editar usuario vera' }))
    await userEvent.click(screen.getByRole('button', { name: 'Guardar' }))

    const put = calls.find((c) => c.method === 'PUT')
    expect(put?.url).toBe('/admin/users/7')
    expect(put?.body).not.toHaveProperty('password')
    expect(put?.body).toMatchObject({ company_ids: [1], branch_ids: [10] })
  })

  it('auditoría filtra por evento y pagina', async () => {
    renderPage(<AuditPage />)

    expect(await screen.findByText('Página 1 de 3 · 120 registros')).toBeInTheDocument()
    await userEvent.type(screen.getByLabelText('Evento'), 'auth.login')
    await userEvent.click(screen.getByRole('button', { name: 'Filtrar' }))
    await screen.findByText('Página 1 de 3 · 120 registros')
    await userEvent.click(screen.getByRole('button', { name: 'Siguiente' }))

    const urls = calls.filter((c) => c.url.startsWith('/admin/audit-logs')).map((c) => c.url)
    expect(urls).toContain('/admin/audit-logs?page=1&event=auth.login')
    expect(urls.at(-1)).toBe('/admin/audit-logs?page=2&event=auth.login')
  })
})
