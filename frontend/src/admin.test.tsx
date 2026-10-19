import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { AuthProvider } from './auth/AuthContext'
import type { AuthUser } from './auth/types'
import AuditPage from './pages/AuditPage'
import OrganizationPage from './pages/OrganizationPage'
import UsersPage from './pages/UsersPage'

const admin: AuthUser = {
  id: 1, username: 'admin', name: 'Ana Admin', role: { slug: 'administrador', name: 'Administrador' },
  permissions: ['organization.manage', 'users.manage', 'audit.view'], companies: [], branches: [],
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

  it('lista compañías y sucursales con su estado en texto', async () => {
    renderPage(<OrganizationPage />)

    const table = await screen.findByRole('table', { name: 'Compañías' })
    expect(within(table).getByText('Inactivo')).toBeInTheDocument()
    expect(await screen.findByText('Alfa Central')).toBeInTheDocument()
  })

  it('crea una compañía y muestra errores de validación por campo', async () => {
    overrides['POST /admin/companies'] = () => new Response(JSON.stringify({ message: 'x', errors: { code: ['El código ya existe.'] } }), { status: 422 })
    renderPage(<OrganizationPage />)

    await userEvent.click(await screen.findByRole('button', { name: 'Nueva compañía' }))
    await userEvent.type(screen.getByLabelText('Código'), 'ALFA')
    await userEvent.type(screen.getByLabelText('Nombre'), 'Otra')
    await userEvent.click(screen.getByRole('button', { name: 'Guardar' }))

    expect(await screen.findByText('Error: El código ya existe.')).toBeInTheDocument()
    expect(calls.find((c) => c.method === 'POST')?.body).toEqual({ code: 'ALFA', name: 'Otra', is_active: true })
  })

  it('al editar una sucursal la compañía no se puede cambiar ni se envía', async () => {
    renderPage(<OrganizationPage />)

    await userEvent.click(await screen.findByRole('button', { name: 'Editar sucursal Alfa Central' }))
    expect(screen.getByLabelText('Compañía')).toBeDisabled()
    await userEvent.click(screen.getByRole('button', { name: 'Guardar' }))

    const put = calls.find((c) => c.method === 'PUT')
    expect(put?.url).toBe('/admin/branches/10')
    expect(put?.body).not.toHaveProperty('company_id')
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
