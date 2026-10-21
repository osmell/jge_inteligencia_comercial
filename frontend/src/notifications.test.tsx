import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { AuthProvider } from './auth/AuthContext'
import NotificationBell from './components/NotificationBell'
import NotificationsPage from './pages/NotificationsPage'
import type { Notice } from './lib/notifications'

let notices: Notice[]
let paused: boolean
let calls: { path: string; method: string; body: unknown }[]
let delivered: boolean

const notice = (o: Partial<Notice>): Notice => ({
  id: 1, type: 'followup_due', type_label: 'Acciones de seguimiento del día o vencidas', severity: 'warning', title: 'Tienes 3 acciones vencidas', body: 'Revisa tu agenda.', url: '/agenda',
  status: 'new', created_at: '2026-10-20T08:00:00Z', company_id: null, ...o,
})

beforeEach(() => {
  document.cookie = 'XSRF-TOKEN=x'
  calls = []
  paused = false
  delivered = true
  notices = [notice({ id: 1 }), notice({ id: 2, title: 'Cotización por vencer', url: null, status: 'seen', severity: 'info', body: null })]
  vi.stubGlobal('fetch', vi.fn(async (url: string, init?: RequestInit) => {
    const path = url.replace('http://localhost:8000/api/v1', '')
    const method = init?.method ?? 'GET'
    calls.push({ path, method, body: init?.body ? JSON.parse(String(init.body)) : undefined })
    let body: unknown = { data: [] }
    if (path === '/auth/me') body = { data: { id: 1, username: 'a', name: 'Ana', role: { slug: 'vendedor', name: 'Vendedor' }, permissions: [], companies: [], branches: [] } }
    else if (path === '/notifications/summary') body = { data: { unread: notices.filter((n) => n.status === 'new').length, latest: notices, paused } }
    else if (path.startsWith('/notifications/preferences')) body = { data: { types: [{ type: 'followup_due', label: 'Acciones de seguimiento del día o vencidas', enabled: true }, { type: 'system', label: 'Avisos del sistema', enabled: false }], paused } }
    else if (path === '/notifications/test') body = { data: null, meta: { delivered } }
    else if (path.startsWith('/notifications?')) body = { data: notices, meta: { has_more: false } }
    return new Response(JSON.stringify(body), { status: 200 })
  }))
})
afterEach(() => vi.unstubAllGlobals())

const renderBell = () => render(<AuthProvider><MemoryRouter><Routes><Route path="/" element={<NotificationBell />} /><Route path="/agenda" element={<p>Pantalla de agenda</p>} /><Route path="/notificaciones" element={<p>Pantalla de avisos</p>} /></Routes></MemoryRouter></AuthProvider>)
const renderPage = () => render(<AuthProvider><MemoryRouter><NotificationsPage /></MemoryRouter></AuthProvider>)

describe('avisos internos', () => {
  it('la campana muestra cuántos avisos nuevos hay', async () => {
    renderBell()

    expect(await screen.findByRole('button', { name: 'Avisos: 1 nuevos' })).toBeInTheDocument()
  })

  it('al abrirla lista los avisos recientes y al pulsar uno lo marca como visto y lleva a su pantalla', async () => {
    const u = userEvent.setup()
    renderBell()

    await u.click(await screen.findByRole('button', { name: 'Avisos: 1 nuevos' }))
    const box = screen.getByRole('region', { name: 'Avisos recientes' })
    expect(box).toHaveTextContent('Tienes 3 acciones vencidas')
    expect(box).toHaveTextContent('Cotización por vencer')
    await u.click(within(box).getByRole('button', { name: /Tienes 3 acciones vencidas/ }))

    expect(await screen.findByText('Pantalla de agenda')).toBeInTheDocument()
    expect(calls.some((c) => c.path === '/notifications/1/seen' && c.method === 'POST')).toBe(true)
  })

  it('permite marcar todo como visto y avisa cuando los avisos están en pausa', async () => {
    paused = true
    const u = userEvent.setup()
    renderBell()

    await u.click(await screen.findByRole('button', { name: 'Avisos: 1 nuevos' }))
    expect(screen.getByText(/Tienes los avisos en pausa/)).toBeInTheDocument()
    await u.click(screen.getByRole('button', { name: 'Marcar todo como visto' }))
    await waitFor(() => expect(calls.some((c) => c.path === '/notifications/seen-all' && c.method === 'POST')).toBe(true))
  })

  it('sin avisos lo dice', async () => {
    notices = []
    const u = userEvent.setup()
    renderBell()

    await u.click(await screen.findByRole('button', { name: 'Avisos' }))
    expect(screen.getByText('No tienes avisos.')).toBeInTheDocument()
  })

  it('la página lista los avisos y permite verlos, abrirlos y descartarlos', async () => {
    const u = userEvent.setup()
    renderPage()

    const list = await screen.findByRole('list', { name: 'Avisos' })
    await waitFor(() => expect(list).toHaveTextContent('Tienes 3 acciones vencidas'))
    expect(list).toHaveTextContent('Atención')
    await u.click(screen.getByRole('button', { name: 'Marcar como visto: Tienes 3 acciones vencidas' }))
    await waitFor(() => expect(calls.some((c) => c.path === '/notifications/1/seen')).toBe(true))
    await u.click(screen.getByRole('button', { name: 'Descartar: Cotización por vencer' }))
    await waitFor(() => expect(calls.some((c) => c.path === '/notifications/2/dismiss')).toBe(true))
  })

  it('las preferencias guardan solo el cambio hecho y pausan todo', async () => {
    const u = userEvent.setup()
    renderPage()

    await u.click(await screen.findByRole('checkbox', { name: 'Avisos del sistema' }))
    await waitFor(() => expect(calls.find((c) => c.method === 'PUT')?.body).toEqual({ types: { system: true } }))
    await u.click(screen.getByRole('checkbox', { name: 'Pausar todos mis avisos' }))
    await waitFor(() => expect(calls.filter((c) => c.method === 'PUT').at(-1)?.body).toEqual({ paused: true }))
  })

  it('el aviso de prueba explica cuando no se entregó', async () => {
    delivered = false
    const u = userEvent.setup()
    renderPage()

    await u.click(await screen.findByRole('button', { name: 'Enviarme un aviso de prueba' }))
    expect(await screen.findByText(/no se entregó porque tienes pausados los avisos/)).toBeInTheDocument()
  })
})
