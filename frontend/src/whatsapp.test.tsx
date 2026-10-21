import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { AuthProvider } from './auth/AuthContext'
import CustomerWhatsapp from './components/CustomerWhatsapp'
import WhatsappSettingsPage from './pages/WhatsappSettingsPage'

let calls: { path: string; method: string; body: unknown }[]
let blockers: string[]

beforeEach(() => {
  document.cookie = 'XSRF-TOKEN=x'
  calls = []
  blockers = []
  vi.stubGlobal('fetch', vi.fn(async (url: string, init?: RequestInit) => {
    const path = url.replace('http://localhost:8000/api/v1', '')
    calls.push({ path, method: init?.method ?? 'GET', body: init?.body ? JSON.parse(String(init.body)) : undefined })
    let body: unknown = { data: [] }
    if (path === '/auth/me') body = { data: { id: 1, username: 'a', name: 'Ana', role: { slug: 'administrador', name: 'Admin' }, permissions: ['whatsapp.manage', 'interactions.manage'], companies: [], branches: [] } }
    else if (path === '/whatsapp/settings') body = { data: { away_enabled: false, away_message: null, away_start_hour: 8, away_end_hour: 18, away_days: [1, 2, 3, 4, 5, 6], unassigned_company_id: null, enabled: false, phone_number_id: '123', business_account_id: null, api_version: 'v21.0', has_access_token: true, has_app_secret: false, has_verify_token: false, ready: false } }
    else if (path === '/whatsapp/test') body = { data: { ok: true, message: 'Conexión correcta con JGE.' } }
    else if (path === '/whatsapp/messages') body = { data: [{ id: 1, customer: 'Cliente Uno', to: '503', body: 'Hola', status: 'not_sent', error: 'Sin consentimiento', created_at: '2026-10-21T12:00:00Z' }] }
    else if (path === '/customers/5/whatsapp') body = { data: { number: '+503 7000-1234', consent: blockers.length ? null : 'granted', blockers, ready: true, consents: [], templates: [{ id: 3, name: 'recordatorio', body: 'Hola {{1}}', variables: 1 }], messages: [] } }
    else if (path === '/customers/5/whatsapp/send') body = { data: { id: 9, body: 'Hola Ana', status: 'sent', error: null, created_at: '2026-10-21T12:00:00Z' } }
    return new Response(JSON.stringify(body), { status: 200 })
  }))
})
afterEach(() => vi.unstubAllGlobals())

describe('WhatsApp', () => {
  it('la administración ve la cuenta sin credenciales, prueba la conexión y ve el registro', async () => {
    const u = userEvent.setup()
    render(<AuthProvider><MemoryRouter><WhatsappSettingsPage /></MemoryRouter></AuthProvider>)

    expect(await screen.findByLabelText('Identificador del número (Phone number ID)')).toHaveValue('123')
    expect(screen.getByLabelText('Token de acceso')).toHaveValue('')
    expect(screen.getByText(/WhatsApp no está activo/)).toBeInTheDocument()
    expect(await screen.findByText('Sin consentimiento')).toBeInTheDocument()
    await u.click(screen.getByRole('button', { name: 'Probar conexión' }))
    expect(await screen.findByText('Conexión correcta con JGE.')).toBeInTheDocument()
  })

  it('en la ficha se envía una plantilla aprobada con sus valores', async () => {
    const u = userEvent.setup()
    render(<AuthProvider><MemoryRouter><CustomerWhatsapp customerId={5} /></MemoryRouter></AuthProvider>)

    expect(await screen.findByText('Aceptó recibir mensajes')).toBeInTheDocument()
    await u.selectOptions(screen.getByLabelText('Plantilla aprobada'), 'recordatorio')
    await u.type(screen.getByLabelText('Valor 1'), 'Ana')
    await u.click(screen.getByRole('button', { name: 'Enviar mensaje' }))
    await waitFor(() => expect(calls.find((c) => c.path === '/customers/5/whatsapp/send')?.body).toEqual({ template_id: 3, params: ['Ana'] }))
  })

  it('si hay impedimentos los explica y no deja enviar', async () => {
    blockers = ['El cliente está marcado «no contactar».']
    render(<AuthProvider><MemoryRouter><CustomerWhatsapp customerId={5} /></MemoryRouter></AuthProvider>)

    expect(await screen.findByText(/No se puede enviar: El cliente está marcado «no contactar»/)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Enviar mensaje' })).toBeDisabled()
  })
})
