import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { AuthProvider } from './auth/AuthContext'
import Layout from './components/Layout'
import { RequireAdministrator, RequireAuth, RequireVisibleOption } from './components/RouteGuards'
import { ToastProvider } from './components/toast'
import { optionForPath } from './lib/menu'
import MenuAccessPage from './pages/MenuAccessPage'

const ALL = ['sales.view', 'customers.view', 'credit.view', 'inventory.view', 'reports.view', 'users.manage']
const EVERYTHING = [...ALL, 'roles.manage', 'audit.view', 'imports.manage', 'companies.manage', 'branches.manage', 'digests.view', 'insights.view', 'conversations.view', 'interactions.manage']
const REPORTS = ['report_sellers', 'report_operators', 'report_branches', 'report_products', 'report_conciliation']

let me: { role: string; permissions: string[]; hidden: string[] }
let calls: { url: string; method: string; body?: string }[]

const options = [
  ...['sales', 'credit', 'stock'].map((key) => ({ key, group: 'Inteligencia', label: key, permission: 'x' })),
  ...REPORTS.map((key) => ({ key, group: 'Reportes', label: key, permission: 'reports.view' })),
]
const people = [
  { id: 7, username: 'gloria', name: 'Gloria Gerente', is_active: true, role: { slug: 'gerente_general', name: 'Gerente General' }, available: ['sales', 'credit', ...REPORTS], hidden: ['credit'] },
  { id: 8, username: 'vera', name: 'Vera Vendedora', is_active: true, role: { slug: 'vendedor', name: 'Vendedor' }, available: ['sales'], hidden: [] },
]

beforeEach(() => {
  document.cookie = 'XSRF-TOKEN=x'
  localStorage.clear()   // lo que cada grupo recuerda del menú no debe pasar de una prueba a otra
  me = { role: 'gerente_general', permissions: ALL, hidden: [] }
  calls = []
  vi.stubGlobal('fetch', vi.fn(async (url: string, init?: RequestInit) => {
    calls.push({ url, method: init?.method ?? 'GET', body: init?.body as string | undefined })
    if (url.endsWith('/auth/me')) return new Response(JSON.stringify({ data: { id: 1, username: 'a', name: 'Ana', role: { slug: me.role, name: me.role }, permissions: me.permissions, hidden_menu: me.hidden, companies: [], branches: [] } }), { status: 200 })
    if (url.endsWith('/admin/menu-access')) return new Response(JSON.stringify({ data: { options, users: people } }), { status: 200 })
    if (url.includes('/admin/menu-access/')) return new Response(JSON.stringify({ data: people[0] }), { status: 200 })
    return new Response(JSON.stringify({ data: [] }), { status: 200 })
  }))
})
afterEach(() => vi.unstubAllGlobals())

const shell = (path: string) =>
  render(
    <AuthProvider>
      <ToastProvider>
        <MemoryRouter initialEntries={[path]}>
          <Routes>
            <Route element={<RequireAuth />}>
              <Route element={<RequireVisibleOption />}>
                <Route element={<Layout />}>
                  <Route index element={<p>Inicio de prueba</p>} />
                  <Route path="credito/:id" element={<p>Detalle de crédito</p>} />
                  <Route element={<RequireAdministrator />}>
                    <Route path="admin/menu-usuarios" element={<MenuAccessPage />} />
                  </Route>
                </Route>
              </Route>
            </Route>
          </Routes>
        </MemoryRouter>
      </ToastProvider>
    </AuthProvider>,
  )

const menu = () => screen.findByRole('navigation', { name: 'Principal' })

describe('opciones del menú', () => {
  it('cada dirección pertenece a la opción más específica, aunque no tenga entrada propia', () => {
    expect(optionForPath('/credito/12')?.key).toBe('credit')
    expect(optionForPath('/cartera')?.key).toBe('credit')
    expect(optionForPath('/inventario/rotacion')?.key).toBe('rotation')
    expect(optionForPath('/inventario/kardex')?.key).toBe('stock')
    expect(optionForPath('/clientes/ficha')?.key).toBe('customers')
    expect(optionForPath('/reportes/productos')?.key).toBe('report_products')
    expect(optionForPath('/')).toBeUndefined()
    expect(optionForPath('/notificaciones')).toBeUndefined()
  })
})

describe('menú principal con opciones ocultas', () => {
  it('quita solo las opciones ocultas y deja las demás', async () => {
    me.hidden = ['credit']
    const user = userEvent.setup()
    shell('/')
    const nav = await menu()

    await user.click(await within(nav).findByRole('button', { name: 'Inteligencia' }))
    expect(within(nav).getByRole('link', { name: 'Ventas' })).toBeInTheDocument()
    expect(within(nav).queryByRole('link', { name: 'Crédito' })).not.toBeInTheDocument()
  })

  it('si se ocultan todas las opciones de un grupo, el grupo desaparece', async () => {
    me.hidden = REPORTS
    shell('/')
    const nav = await menu()

    await within(nav).findByRole('button', { name: 'Inteligencia' })
    expect(within(nav).queryByRole('button', { name: 'Reportes' })).not.toBeInTheDocument()
  })

  it('con todas visibles el grupo Reportes está', async () => {
    shell('/')

    expect(await within(await menu()).findByRole('button', { name: 'Reportes' })).toBeInTheDocument()
  })

  it('una opción oculta no se abre escribiendo la dirección, ni sus pantallas internas', async () => {
    me.hidden = ['credit']
    shell('/credito/5')

    expect(await screen.findByText('Inicio de prueba')).toBeInTheDocument()
    expect(screen.queryByText('Detalle de crédito')).not.toBeInTheDocument()
  })

  it('una opción visible sí se abre', async () => {
    shell('/credito/5')

    expect(await screen.findByText('Detalle de crédito')).toBeInTheDocument()
  })

  it('«Menú por usuario» solo lo ve un administrador', async () => {
    shell('/')
    const user = userEvent.setup()
    const nav = await menu()
    await user.click(await within(nav).findByRole('button', { name: 'Administración' }))   // el gerente de la prueba sí tiene «Usuarios»
    expect(within(nav).getByRole('link', { name: 'Usuarios' })).toBeInTheDocument()
    expect(within(nav).queryByRole('link', { name: 'Menú por usuario' })).not.toBeInTheDocument()
  })

  it('un administrador ve la opción y su pantalla; quien no lo es vuelve al inicio', async () => {
    me = { role: 'administrador', permissions: ALL, hidden: [] }
    shell('/admin/menu-usuarios')
    expect(await screen.findByRole('heading', { name: 'Menú por usuario' })).toBeInTheDocument()
    expect(within(await menu()).getByRole('link', { name: 'Menú por usuario' })).toBeInTheDocument()   // el grupo se abre solo dentro de su pantalla
  })

  it('quien no es administrador no abre la pantalla escribiendo la dirección', async () => {
    shell('/admin/menu-usuarios')

    expect(await screen.findByText('Inicio de prueba')).toBeInTheDocument()
  })
})

describe('grupos del menú', () => {
  it('todos son desplegables, en este orden, y solo General empieza abierto', async () => {
    me = { role: 'administrador', permissions: EVERYTHING, hidden: [] }
    shell('/')
    const nav = await menu()

    await within(nav).findByRole('button', { name: 'Datos' })
    const groups = await within(nav).findAllByRole('button')
    expect(groups.map((b) => b.textContent)).toEqual(['General', 'Administración', 'Catálogos', 'Inteligencia', 'Reportes', 'Datos'])
    expect(groups.map((b) => b.getAttribute('aria-expanded'))).toEqual(['true', 'false', 'false', 'false', 'false', 'false'])
    expect(within(nav).getByRole('link', { name: 'Inicio' })).toBeVisible()
  })

  it('cada opción está en el grupo acordado', async () => {
    const user = userEvent.setup()
    me = { role: 'administrador', permissions: EVERYTHING, hidden: [] }
    shell('/')
    const nav = await menu()
    const labels = async (group: string) => {
      const button = await within(nav).findByRole('button', { name: group })
      if (button.getAttribute('aria-expanded') !== 'true') await user.click(button)
      return within(within(nav).getByRole('list', { name: group })).getAllByRole('link').map((a) => a.textContent)
    }

    expect(await labels('General')).toEqual(['Inicio', 'Conversaciones', 'Agenda'])
    expect(await labels('Administración')).toEqual(['Usuarios', 'Menú por usuario', 'Roles y permisos', 'Auditoría'])
    expect(await labels('Catálogos')).toEqual(['Compañías', 'Sucursales', 'Clientes', 'Productos', 'Unidades'])
    expect(await labels('Inteligencia')).toEqual(['Ventas', 'Metas', 'Seguimiento', 'Cotizaciones', 'Crédito', 'Resúmenes', 'Inteligencia', 'Existencias', 'Rotación', 'Movimientos'])
    expect(await labels('Reportes')).toEqual(['Vendedores', 'Operadores', 'Sucursales', 'Productos', 'Conciliación'])
    expect(await labels('Datos')).toEqual(['Importaciones'])
  })
})

describe('pantalla «Menú por usuario»', () => {
  const open = async () => {
    me = { role: 'administrador', permissions: ALL, hidden: [] }
    shell('/admin/menu-usuarios')
    await screen.findByLabelText('Persona')
  }

  it('muestra lo que la persona ve y bloquea lo que su rol no permite', async () => {
    await open()

    expect(screen.getByRole('checkbox', { name: 'Inteligencia: Ventas' })).toBeChecked()
    expect(screen.getByRole('checkbox', { name: 'Inteligencia: Crédito' })).not.toBeChecked()   // oculto por la administración
    expect(screen.getByRole('checkbox', { name: 'Inteligencia: Crédito' })).toBeEnabled()
    expect(screen.getByRole('checkbox', { name: 'Inteligencia: Existencias' })).toBeDisabled()   // su rol no lo permite
  })

  it('cuando el rol no permite todo explica por qué y lleva a «Roles y permisos»', async () => {
    await open()

    expect(screen.getByText(/El rol Gerente General permite \d+ de \d+ opciones del menú/)).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Roles y permisos' })).toHaveAttribute('href', '/admin/roles')
  })

  it('al desmarcar todas las opciones de un grupo avisa que el grupo desaparece, y guarda lo elegido', async () => {
    const user = userEvent.setup()
    await open()

    await user.click(screen.getByRole('checkbox', { name: 'Todo el grupo Reportes' }))
    expect(screen.getByText(/dejará de ver el grupo Reportes/)).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Guardar cambios' }))

    const put = calls.find((c) => c.method === 'PUT')!
    expect(put.url).toContain('/admin/menu-access/7')
    expect(JSON.parse(put.body!).hidden).toEqual(['credit', ...REPORTS])
  })

  it('«Mostrar todo» deja la lista vacía y descartar vuelve a lo guardado', async () => {
    const user = userEvent.setup()
    await open()

    await user.click(screen.getByRole('button', { name: 'Mostrar todo' }))
    expect(screen.getByRole('checkbox', { name: 'Inteligencia: Crédito' })).toBeChecked()
    await user.click(screen.getByRole('button', { name: 'Descartar' }))
    expect(screen.getByRole('checkbox', { name: 'Inteligencia: Crédito' })).not.toBeChecked()
  })

  it('con cambios sin guardar no se puede elegir a otra persona', async () => {
    const user = userEvent.setup()
    await open()

    await user.click(screen.getByRole('checkbox', { name: 'Inteligencia: Ventas' }))

    expect(screen.getByLabelText('Persona')).toBeDisabled()
    expect(screen.getByText(/cambios sin guardar/)).toBeInTheDocument()
  })

  it('cambiar de persona muestra su propio menú', async () => {
    const user = userEvent.setup()
    await open()

    await user.selectOptions(screen.getByLabelText('Persona'), '8')

    expect(screen.getByRole('checkbox', { name: 'Reportes: Reporte de vendedores' })).toBeDisabled()
  })
})
