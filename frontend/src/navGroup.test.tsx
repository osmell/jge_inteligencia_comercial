import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { Home, Store } from 'lucide-react'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import NavGroupView from './components/NavGroupView'
import type { NavGroup, NavItem } from './components/navigation'

const items: NavItem[] = [
  { to: '/reportes/vendedores', label: 'Vendedores', title: 'Reporte de vendedores', icon: Store },
  { to: '/reportes/operadores', label: 'Operadores', icon: Home },
]
const dropdown: NavGroup = { title: 'Reportes', collapsible: true, items }
const plain: NavGroup = { title: 'Inteligencia', items }

const view = (group: NavGroup, path = '/') =>
  render(
    <MemoryRouter initialEntries={[path]}>
      <NavGroupView group={group} items={items} renderItem={(i) => <a href={i.to}>{i.label}</a>} />
    </MemoryRouter>,
  )

beforeEach(() => localStorage.clear())
afterEach(() => vi.restoreAllMocks())

describe('grupo desplegable del menú', () => {
  it('un grupo normal muestra su lista siempre, sin botón', () => {
    view(plain)

    expect(screen.getByText('Inteligencia')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Inteligencia' })).not.toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Vendedores' })).toBeVisible()
  })

  it('el desplegable empieza cerrado y sus opciones no se ven ni se alcanzan con el teclado', () => {
    view(dropdown)

    const button = screen.getByRole('button', { name: 'Reportes' })
    expect(button).toHaveAttribute('aria-expanded', 'false')
    expect(screen.queryByRole('link', { name: 'Vendedores' })).not.toBeInTheDocument()   // hidden: fuera del árbol de accesibilidad
  })

  it('se abre y se cierra con el botón', async () => {
    const user = userEvent.setup()
    view(dropdown)
    const button = screen.getByRole('button', { name: 'Reportes' })

    await user.click(button)
    expect(button).toHaveAttribute('aria-expanded', 'true')
    expect(screen.getByRole('link', { name: 'Vendedores' })).toBeVisible()
    expect(screen.getByRole('link', { name: 'Operadores' })).toBeVisible()

    await user.click(button)
    expect(button).toHaveAttribute('aria-expanded', 'false')
    expect(screen.queryByRole('link', { name: 'Vendedores' })).not.toBeInTheDocument()
  })

  it('se abre solo cuando se está dentro de una de sus pantallas', () => {
    view(dropdown, '/reportes/operadores')

    expect(screen.getByRole('button', { name: 'Reportes' })).toHaveAttribute('aria-expanded', 'true')
    expect(screen.getByRole('link', { name: 'Operadores' })).toBeVisible()
  })

  it('recuerda en el navegador si se dejó abierto', async () => {
    const user = userEvent.setup()
    const first = view(dropdown)
    await user.click(screen.getByRole('button', { name: 'Reportes' }))
    first.unmount()

    view(dropdown)

    expect(screen.getByRole('button', { name: 'Reportes' })).toHaveAttribute('aria-expanded', 'true')
  })

  it('si el navegador no deja guardar ni leer, el menú funciona igual', async () => {
    vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => { throw new Error('bloqueado') })
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => { throw new Error('bloqueado') })
    const user = userEvent.setup()
    view(dropdown)

    await user.click(screen.getByRole('button', { name: 'Reportes' }))

    expect(screen.getByRole('link', { name: 'Vendedores' })).toBeVisible()
  })
})
