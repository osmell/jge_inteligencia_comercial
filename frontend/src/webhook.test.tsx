import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { AuthProvider } from './auth/AuthContext'
import WebhookSettingsPage from './pages/WebhookSettingsPage'

let calls: { path: string; method: string; body: unknown }[]

beforeEach(() => {
  document.cookie = 'XSRF-TOKEN=x'
  calls = []
  vi.stubGlobal('fetch', vi.fn(async (url: string, init?: RequestInit) => {
    const path = url.replace('http://localhost:8000/api/v1', '')
    calls.push({ path, method: init?.method ?? 'GET', body: init?.body ? JSON.parse(String(init.body)) : undefined })
    let body: unknown = { data: [] }
    if (path === '/auth/me') body = { data: { id: 1, username: 'a', name: 'Ana', role: { slug: 'administrador', name: 'Admin' }, permissions: ['webhooks.manage'], companies: [], branches: [] } }
    else if (path === '/webhook/settings') body = { data: { enabled: false, url: 'https://n8n.example.com/h', has_secret: true, events: ['digest'], available_events: [{ type: 'digest', label: 'Resúmenes para gerencia' }, { type: 'followup_due', label: 'Seguimiento vencido' }], ready: false } }
    else if (path === '/webhook/test') body = { data: { id: 1, status: 'sent', error: null } }
    else if (path === '/webhook/log') body = { data: [{ id: 1, event: 'digest', status: 'failed', attempt: 3, http_status: 500, error: 'El destino respondió 500.', created_at: '2026-10-21T12:00:00Z' }] }
    return new Response(JSON.stringify(body), { status: 200 })
  }))
})
afterEach(() => vi.unstubAllGlobals())

describe('webhook de salida', () => {
  it('muestra la configuración sin el secreto y el registro de envíos', async () => {
    render(<AuthProvider><MemoryRouter><WebhookSettingsPage /></MemoryRouter></AuthProvider>)

    expect(await screen.findByLabelText('Dirección de destino (URL)')).toHaveValue('https://n8n.example.com/h')
    expect(screen.getByLabelText('Secreto de firma')).toHaveValue('')
    expect(screen.getByLabelText('Resúmenes para gerencia')).toBeChecked()
    expect(screen.getByLabelText('Seguimiento vencido')).not.toBeChecked()
    expect(screen.getByText(/El webhook no está activo/)).toBeInTheDocument()
    expect(await screen.findByText('El destino respondió 500.')).toBeInTheDocument()
  })

  it('guarda los eventos elegidos sin tocar el secreto y envía la prueba', async () => {
    const u = userEvent.setup()
    render(<AuthProvider><MemoryRouter><WebhookSettingsPage /></MemoryRouter></AuthProvider>)

    await u.click(await screen.findByLabelText('Seguimiento vencido'))
    await u.click(screen.getByRole('button', { name: 'Guardar' }))
    await waitFor(() => expect(calls.find((c) => c.method === 'PUT')?.body).toMatchObject({ url: 'https://n8n.example.com/h', secret: '', events: ['digest', 'followup_due'] }))
    await u.click(screen.getByRole('button', { name: 'Enviar prueba' }))
    await waitFor(() => expect(calls.some((c) => c.path === '/webhook/test' && c.method === 'POST')).toBe(true))
  })
})
