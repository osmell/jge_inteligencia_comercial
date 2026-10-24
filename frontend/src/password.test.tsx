import { act, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { AuthProvider } from './auth/AuthContext'
import { SESSION_EXPIRED_EVENT } from './lib/api'
import type { AuthUser } from './auth/types'
import { RequireAuth, RequireSession } from './components/RouteGuards'
import { ToastProvider } from './components/toast'
import ChangePasswordPage from './pages/ChangePasswordPage'

const base: AuthUser = { id: 3, username: 'nuevo', name: 'Nuevo Usuario', role: { slug: 'vendedor', name: 'Vendedor' }, permissions: ['customers.view'], companies: [], branches: [] }
let me: AuthUser
let post: (body: Record<string, string>) => { status: number; body: unknown }
let posted: Record<string, string> | null

beforeEach(() => {
  document.cookie = 'XSRF-TOKEN=x'
  posted = null
  vi.stubGlobal('fetch', vi.fn(async (url: string, init?: RequestInit) => {
    if (url.endsWith('/auth/me')) return new Response(JSON.stringify({ data: me }), { status: 200 })
    if (url.endsWith('/auth/password') && init?.method === 'POST') {
      posted = JSON.parse(String(init.body)) as Record<string, string>
      const r = post(posted)
      return new Response(JSON.stringify(r.body), { status: r.status })
    }
    return new Response(JSON.stringify({ data: [] }), { status: 200 })
  }))
})
afterEach(() => vi.unstubAllGlobals())

function renderApp(path: string) {
  return render(
    <MemoryRouter initialEntries={[path]}>
      <AuthProvider>
        <ToastProvider>
          <Routes>
            <Route element={<RequireSession />}><Route path="/cambiar-clave" element={<ChangePasswordPage />} /></Route>
            <Route element={<RequireAuth />}><Route index element={<p>Inicio del sistema</p>} /></Route>
            <Route path="/login" element={<p>Pantalla de ingreso</p>} />
          </Routes>
        </ToastProvider>
      </AuthProvider>
    </MemoryRouter>,
  )
}

describe('cambio de clave obligatorio', () => {
  it('una cuenta con clave temporal no entra al sistema: la llevan a crear su clave', async () => {
    me = { ...base, must_change_password: true }
    renderApp('/')

    expect(await screen.findByRole('heading', { name: 'Crea tu clave' })).toBeInTheDocument()
    expect(screen.queryByText('Inicio del sistema')).not.toBeInTheDocument()
    expect(screen.getByText(/tu clave actual es temporal/i)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Salir sin cambiarla' })).toBeInTheDocument()
  })

  it('una cuenta normal entra al sistema y puede cambiar su clave a voluntad', async () => {
    me = { ...base, must_change_password: false }
    renderApp('/')
    expect(await screen.findByText('Inicio del sistema')).toBeInTheDocument()

    renderApp('/cambiar-clave')
    expect(await screen.findByRole('heading', { name: 'Cambiar mi clave' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: /Volver/ })).toBeInTheDocument()
  })

  it('sin sesión manda a ingresar', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify({ message: 'No autenticado' }), { status: 401 })))
    renderApp('/cambiar-clave')

    expect(await screen.findByText('Pantalla de ingreso')).toBeInTheDocument()
  })

  it('el botón se activa solo con los tres campos y envía la clave actual, la nueva y su confirmación', async () => {
    me = { ...base, must_change_password: true }
    post = () => ({ status: 200, body: { data: { ...base, must_change_password: false } } })
    const user = userEvent.setup()
    renderApp('/')
    await screen.findByRole('heading', { name: 'Crea tu clave' })
    const save = screen.getByRole('button', { name: 'Guardar clave' })
    expect(save).toBeDisabled()

    await user.type(screen.getByLabelText('Clave temporal'), 'Temporal-12345')
    await user.type(screen.getByLabelText('Clave nueva'), 'Definitiva-67890')
    expect(save).toBeDisabled()
    await user.type(screen.getByLabelText('Repite la clave nueva'), 'Definitiva-67890')
    expect(save).toBeEnabled()
    await user.click(save)

    await waitFor(() => expect(posted).toEqual({ current_password: 'Temporal-12345', password: 'Definitiva-67890', password_confirmation: 'Definitiva-67890' }))
  })

  it('al guardar con éxito entra al sistema sin volver a iniciar sesión', async () => {
    me = { ...base, must_change_password: true }
    post = () => ({ status: 200, body: { data: { ...base, must_change_password: false } } })
    const user = userEvent.setup()
    renderApp('/')
    await screen.findByRole('heading', { name: 'Crea tu clave' })

    await user.type(screen.getByLabelText('Clave temporal'), 'Temporal-12345')
    await user.type(screen.getByLabelText('Clave nueva'), 'Definitiva-67890')
    await user.type(screen.getByLabelText('Repite la clave nueva'), 'Definitiva-67890')
    await user.click(screen.getByRole('button', { name: 'Guardar clave' }))

    expect(await screen.findByText('Inicio del sistema')).toBeInTheDocument()
  })

  it('muestra los errores del servidor junto a cada campo y no avanza', async () => {
    me = { ...base, must_change_password: true }
    post = () => ({ status: 422, body: { message: 'Datos no válidos', errors: { current_password: ['La clave actual no es correcta.'], password: ['La nueva clave debe ser distinta de la actual.'] } } })
    const user = userEvent.setup()
    renderApp('/')
    await screen.findByRole('heading', { name: 'Crea tu clave' })

    await user.type(screen.getByLabelText('Clave temporal'), 'Mala-clave-1')
    await user.type(screen.getByLabelText('Clave nueva'), 'Temporal-12345')
    await user.type(screen.getByLabelText('Repite la clave nueva'), 'Temporal-12345')
    await user.click(screen.getByRole('button', { name: 'Guardar clave' }))

    expect(await screen.findByText(/La clave actual no es correcta/)).toBeInTheDocument()
    expect(screen.getByText(/La nueva clave debe ser distinta/)).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'Crea tu clave' })).toBeInTheDocument()
  })
})

describe('sesión revocada en medio del uso', () => {
  it('si el servidor deja de reconocer la sesión, la persona vuelve a la pantalla de ingreso', async () => {
    me = { ...base, must_change_password: false }
    renderApp('/')
    expect(await screen.findByText('Inicio del sistema')).toBeInTheDocument()

    act(() => {
      window.dispatchEvent(new Event(SESSION_EXPIRED_EVENT))
    })

    expect(await screen.findByText('Pantalla de ingreso')).toBeInTheDocument()
  })
})
