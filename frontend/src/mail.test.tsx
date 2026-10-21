import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { AuthProvider } from './auth/AuthContext'
import MailSettingsPage from './pages/MailSettingsPage'

let calls: { path: string; method: string; body: unknown }[]

beforeEach(() => {
  document.cookie = 'XSRF-TOKEN=x'
  calls = []
  vi.stubGlobal('fetch', vi.fn(async (url: string, init?: RequestInit) => {
    const path = url.replace('http://localhost:8000/api/v1', '')
    calls.push({ path, method: init?.method ?? 'GET', body: init?.body ? JSON.parse(String(init.body)) : undefined })
    let body: unknown = { data: [] }
    if (path === '/auth/me') body = { data: { id: 1, username: 'a', name: 'Ana', role: { slug: 'administrador', name: 'Admin' }, permissions: ['mail.manage'], companies: [], branches: [] } }
    else if (path === '/mail/settings') body = { data: { enabled: false, host: 'smtp.example.com', port: 587, encryption: 'starttls', username: 'u', has_password: true, from_address: 'a@example.com', from_name: 'JGE', ready: false } }
    else if (path === '/mail/test') body = { data: { id: 1, status: 'sent', error: null } }
    else if (path === '/mail/log') body = { data: [{ id: 1, to: 'x@example.com', kind: 'test', subject: 'Correo de prueba', status: 'not_sent', attempt: 1, error: 'El correo no está activado.', created_at: '2026-10-21T12:00:00Z' }] }
    return new Response(JSON.stringify(body), { status: 200 })
  }))
})
afterEach(() => vi.unstubAllGlobals())

describe('configuración de correo', () => {
  it('muestra la configuración sin la contraseña, el aviso de inactivo y el registro', async () => {
    render(<AuthProvider><MemoryRouter><MailSettingsPage /></MemoryRouter></AuthProvider>)

    expect(await screen.findByLabelText('Servidor (SMTP)')).toHaveValue('smtp.example.com')
    expect(screen.getByLabelText('Contraseña')).toHaveValue('')
    expect(screen.getByText(/Hay una contraseña guardada/)).toBeInTheDocument()
    expect(screen.getByText(/El correo no está activo/)).toBeInTheDocument()
    expect(await screen.findByText('El correo no está activado.')).toBeInTheDocument()
  })

  it('guarda sin contraseña si no se escribe una y envía el correo de prueba', async () => {
    const u = userEvent.setup()
    render(<AuthProvider><MemoryRouter><MailSettingsPage /></MemoryRouter></AuthProvider>)

    await u.click(await screen.findByLabelText('Enviar correos desde el sistema'))
    await u.click(screen.getByRole('button', { name: 'Guardar' }))
    await waitFor(() => expect(calls.find((c) => c.method === 'PUT')?.body).toMatchObject({ enabled: true, host: 'smtp.example.com', port: 587, password: '' }))

    await u.type(screen.getByLabelText('Enviar correo de prueba a'), 'x@example.com')
    await u.click(screen.getByRole('button', { name: 'Enviar prueba' }))
    await waitFor(() => expect(calls.find((c) => c.path === '/mail/test')?.body).toEqual({ to: 'x@example.com' }))
  })
})
