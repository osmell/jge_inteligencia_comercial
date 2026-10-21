import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import App from './App'
import type { AuthUser } from './auth/types'

const vendedor: AuthUser = {
  id: 2, username: 'vendedor', name: 'Vera Vendedora',
  role: { slug: 'vendedor', name: 'Vendedor' }, permissions: [], companies: [], branches: [],
}
const admin: AuthUser = {
  id: 1, username: 'admin', name: 'Ana Admin',
  role: { slug: 'administrador', name: 'Administrador' },
  permissions: ['companies.manage', 'branches.manage', 'users.manage', 'roles.manage', 'audit.view', 'imports.manage'], companies: [], branches: [],
}

function mockApi(handler: (url: string, init?: RequestInit) => { status: number; body?: unknown }) {
  vi.stubGlobal('fetch', vi.fn(async (url: string, init?: RequestInit) => {
    // El dashboard del inicio no es lo que prueba este archivo: responde sin secciones.
    const { status, body } = url.endsWith('/dashboard')
      ? { status: 200, body: { data: { profile: 'company', linked_sellers: null, sections: [], periods: ['month'], companies: [], last_sale: null, last_import: null } } }
      : handler(url, init)
    return new Response(status === 204 ? null : JSON.stringify(body ?? {}), { status })
  }))
}

describe('App', () => {
  beforeEach(() => {
    window.history.pushState({}, '', '/')
    document.cookie = 'XSRF-TOKEN=token; path=/'
  })
  afterEach(() => vi.unstubAllGlobals())

  it('redirige al login cuando no hay sesión', async () => {
    mockApi(() => ({ status: 401, body: { message: 'Unauthenticated.' } }))
    render(<App />)

    expect(await screen.findByRole('button', { name: 'Ingresar' })).toBeInTheDocument()
    expect(window.location.pathname).toBe('/login')
  })

  it('muestra el error del servidor cuando las credenciales son incorrectas', async () => {
    mockApi((url) => url.endsWith('/auth/login')
      ? { status: 422, body: { message: 'x', errors: { username: ['Usuario o contraseña incorrectos.'] } } }
      : { status: 401 })
    render(<App />)

    await userEvent.type(await screen.findByLabelText('Usuario'), 'admin')
    await userEvent.type(screen.getByLabelText('Contraseña'), 'mala')
    await userEvent.click(screen.getByRole('button', { name: 'Ingresar' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('Usuario o contraseña incorrectos.')
  })

  it('inicia sesión y entra al inicio', async () => {
    mockApi((url) => url.endsWith('/auth/login') ? { status: 200, body: { data: vendedor } } : { status: 401 })
    render(<App />)

    await userEvent.type(await screen.findByLabelText('Usuario'), 'vendedor')
    await userEvent.type(screen.getByLabelText('Contraseña'), 'Clave-segura-1')
    await userEvent.click(screen.getByRole('button', { name: 'Ingresar' }))

    expect(await screen.findByText('Hola, Vera Vendedora')).toBeInTheDocument()
  })

  it('el vendedor no ve el menú administrativo ni accede por URL', async () => {
    window.history.pushState({}, '', '/admin/usuarios')
    mockApi(() => ({ status: 200, body: { data: vendedor } }))
    render(<App />)

    expect(await screen.findByText('Hola, Vera Vendedora')).toBeInTheDocument()
    expect(screen.queryByRole('link', { name: 'Usuarios' })).not.toBeInTheDocument()
    expect(screen.queryByRole('link', { name: 'Auditoría' })).not.toBeInTheDocument()
    expect(window.location.pathname).toBe('/')
  })

  it('el administrador ve todo el menú', async () => {
    mockApi(() => ({ status: 200, body: { data: admin } }))
    render(<App />)

    const nav = (await screen.findAllByRole('navigation', { name: 'Principal' }))[0]
    for (const label of ['Inicio', 'Importaciones', 'Compañías', 'Sucursales', 'Usuarios', 'Roles y permisos', 'Auditoría']) {
      expect(nav).toHaveTextContent(label)
    }
  })

  it('cierra sesión y vuelve al login', async () => {
    mockApi((url) => url.endsWith('/auth/logout') ? { status: 204 } : { status: 200, body: { data: admin } })
    render(<App />)

    await userEvent.click(await screen.findByRole('button', { name: 'Cerrar sesión' }))

    await waitFor(() => expect(window.location.pathname).toBe('/login'))
  })
})
