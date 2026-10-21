import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { AuthProvider } from './auth/AuthContext'
import CommandBar from './components/CommandBar'

let permissions: string[]

beforeEach(() => {
  document.cookie = 'XSRF-TOKEN=x'
  permissions = ['sales.view', 'customers.view']
  vi.stubGlobal('fetch', vi.fn(async (url: string) => {
    const body = url.endsWith('/auth/me') ? { data: { id: 1, username: 'a', name: 'Ana', role: { slug: 'vendedor', name: 'Vendedor' }, permissions, companies: [], branches: [] } } : { data: [] }
    return new Response(JSON.stringify(body), { status: 200 })
  }))
})
afterEach(() => vi.unstubAllGlobals())

function Where() {
  return <p data-testid="where">{useLocation().pathname}</p>
}
const renderBar = () =>
  render(
    <AuthProvider>
      <MemoryRouter>
        <CommandBar />
        <textarea aria-label="Otro campo" />
        <Routes><Route path="*" element={<Where />} /></Routes>
      </MemoryRouter>
    </AuthProvider>,
  )

describe('barra de comandos', () => {
  it('la tecla / enfoca la barra, pero no cuando se escribe en otro campo', async () => {
    const u = userEvent.setup()
    renderBar()
    const bar = await screen.findByRole('combobox', { name: /Ir a una pantalla/ })

    await u.keyboard('/')
    expect(bar).toHaveFocus()
    expect(bar).toHaveValue('')                                                                  // la barra no se escribe a sí misma

    await u.click(screen.getByLabelText('Otro campo'))
    await u.keyboard('a/b')
    expect(screen.getByLabelText('Otro campo')).toHaveValue('a/b')
    expect(bar).not.toHaveFocus()
  })

  it('filtra las pantallas por nombre sin acentos, con o sin la barra inicial, y Enter va a la primera', async () => {
    const u = userEvent.setup()
    renderBar()
    const bar = await screen.findByRole('combobox', { name: /Ir a una pantalla/ })

    await u.click(bar)
    await u.type(bar, '/cotizacion')
    const list = screen.getByRole('listbox', { name: 'Pantallas' })
    expect(within(list).getAllByRole('option')).toHaveLength(1)
    await u.keyboard('{Enter}')

    expect(screen.getByTestId('where')).toHaveTextContent('/cotizaciones')
    expect(bar).toHaveValue('')
    expect(screen.queryByRole('listbox')).not.toBeInTheDocument()
  })

  it('las flechas eligen y el clic navega; solo ofrece lo que la persona puede ver', async () => {
    const u = userEvent.setup()
    renderBar()
    const bar = await screen.findByRole('combobox', { name: /Ir a una pantalla/ })

    await u.click(bar)
    const names = screen.getAllByRole('option').map((o) => o.textContent)
    expect(names.some((n) => n?.includes('Ventas'))).toBe(true)
    expect(names.some((n) => n?.includes('Usuarios'))).toBe(false)                              // sin permiso de administración
    expect(names.some((n) => n?.includes('Inicio'))).toBe(true)                                 // lo que no pide permiso

    await u.type(bar, 'ven')
    await u.keyboard('{ArrowDown}{ArrowUp}{Enter}')
    expect(screen.getByTestId('where')).toHaveTextContent('/ventas')

    await u.click(bar)
    await u.type(bar, 'client')
    await u.click(screen.getByRole('option', { name: /Clientes/ }))
    expect(screen.getByTestId('where')).toHaveTextContent('/clientes')
  })

  it('Escape limpia y cierra, y sin coincidencias lo dice', async () => {
    const u = userEvent.setup()
    renderBar()
    const bar = await screen.findByRole('combobox', { name: /Ir a una pantalla/ })

    await u.type(bar, 'zzzz')
    expect(screen.getByText('No hay pantallas que coincidan.')).toBeInTheDocument()
    await u.keyboard('{Enter}')
    expect(screen.getByTestId('where')).toHaveTextContent('/')                                  // no navega a ninguna parte
    await u.keyboard('{Escape}')
    expect(bar).toHaveValue('')
    expect(screen.queryByRole('listbox')).not.toBeInTheDocument()
  })
})
