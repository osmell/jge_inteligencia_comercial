import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { AuthProvider } from './auth/AuthContext'
import ReportActions from './components/reports/ReportActions'
import { parseRecipients } from './lib/recipients'
import { initialReportFilters, reportQuery } from './lib/reports'
import type { ReportFilters } from './lib/useReportFilters'

const filters = (over: Partial<ReportFilters> = {}): ReportFilters => ({
  values: initialReportFilters(),
  set: () => undefined,
  setPreset: () => undefined,
  setCompany: () => undefined,
  context: null,
  contextError: null,
  valid: true,
  query: (extra?: Record<string, string>) => reportQuery({ ...initialReportFilters(), from: '2026-10-01', to: '2026-10-31' }, extra),
  ...over,
})

interface Call { url: string; method: string; body: Record<string, unknown> | null }
let calls: Call[]
let emailResponse: { status: number; body: unknown }

beforeEach(() => {
  document.cookie = 'XSRF-TOKEN=x'
  calls = []
  emailResponse = { status: 200, body: { data: { results: [{ to: 'jefe@ejemplo.com', status: 'sent', error: null }], sent: 1, failed: 0 } } }
  vi.stubGlobal('fetch', vi.fn(async (url: string, init?: RequestInit) => {
    calls.push({ url, method: init?.method ?? 'GET', body: init?.body ? (JSON.parse(String(init.body)) as Record<string, unknown>) : null })
    if (url.endsWith('/auth/me')) return new Response(JSON.stringify({ data: { id: 1, username: 'g', name: 'Gerente Uno', role: { slug: 'gerente_general', name: 'Gerente' }, permissions: ['reports.view'], companies: [], branches: [] } }), { status: 200 })
    if (url.includes('/export?')) return new Response('contenido', { status: 200, headers: { 'content-disposition': 'attachment; filename="reporte.xlsx"' } })
    if (url.includes('/email')) return new Response(JSON.stringify(emailResponse.body), { status: emailResponse.status })
    return new Response('{}', { status: 200 })
  }))
  URL.createObjectURL = vi.fn(() => 'blob:x')
  URL.revokeObjectURL = vi.fn()
})
afterEach(() => vi.unstubAllGlobals())

const renderActions = (f = filters()) => render(<AuthProvider><ReportActions report="sellers" filters={f} /></AuthProvider>)

describe('direcciones de correo', () => {
  it('separa por coma, punto y coma, espacio o línea, sin repetir ni distinguir mayúsculas', () => {
    expect(parseRecipients('Jefe@Ejemplo.com, dueno@ejemplo.com;\njefe@ejemplo.com  otro@x.org')).toEqual({ valid: ['jefe@ejemplo.com', 'dueno@ejemplo.com', 'otro@x.org'], invalid: [] })
  })

  it('separa las que no son válidas', () => {
    expect(parseRecipients('bien@ejemplo.com mal-correo @sin.usuario')).toEqual({ valid: ['bien@ejemplo.com'], invalid: ['mal-correo', '@sin.usuario'] })
  })

  it('un texto vacío no tiene destinatarios', () => {
    expect(parseRecipients('  \n ')).toEqual({ valid: [], invalid: [] })
  })
})

describe('salidas del reporte', () => {
  it('descarga el Excel con el mismo periodo y filtros de la pantalla', async () => {
    const user = userEvent.setup()
    renderActions()

    await user.click(screen.getByRole('button', { name: 'Excel' }))

    await waitFor(() => expect(calls.some((c) => c.url.includes('/reports/sellers/export?') && c.url.includes('format=xlsx') && c.url.includes('from=2026-10-01') && c.url.includes('to=2026-10-31'))).toBe(true))
  })

  it('descarga el PDF', async () => {
    const user = userEvent.setup()
    renderActions()

    await user.click(screen.getByRole('button', { name: 'PDF' }))

    await waitFor(() => expect(calls.some((c) => c.url.includes('format=pdf'))).toBe(true))
  })

  it('con un periodo inválido ninguna salida está disponible', () => {
    renderActions(filters({ valid: false }))

    for (const name of ['Imprimir', 'Excel', 'PDF', 'Enviar por correo']) expect(screen.getByRole('button', { name })).toBeDisabled()
  })

  it('imprimir abre el diálogo de impresión', async () => {
    const print = vi.spyOn(window, 'print').mockImplementation(() => undefined)
    const user = userEvent.setup()
    renderActions()

    await user.click(screen.getByRole('button', { name: 'Imprimir' }))

    expect(print).toHaveBeenCalledTimes(1)
    print.mockRestore()
  })

  it('un error al descargar se muestra', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify({ message: 'No se pudo generar' }), { status: 500 })))
    const user = userEvent.setup()
    renderActions()

    await user.click(screen.getByRole('button', { name: 'Excel' }))

    expect(await screen.findByText(/No se pudo generar/)).toBeInTheDocument()
  })
})

describe('enviar por correo', () => {
  async function open() {
    const user = userEvent.setup()
    renderActions()
    await user.click(screen.getByRole('button', { name: 'Enviar por correo' }))
    return user
  }

  it('no deja revisar sin destinatarios, con una dirección inválida o con más de diez', async () => {
    const user = await open()
    const review = screen.getByRole('button', { name: 'Revisar destinatarios' })
    expect(review).toBeDisabled()

    await user.type(screen.getByLabelText('Para'), 'mal-correo')
    expect(review).toBeDisabled()
    expect(screen.getByText(/No es una dirección válida: mal-correo/)).toBeInTheDocument()

    await user.clear(screen.getByLabelText('Para'))
    await user.type(screen.getByLabelText('Para'), Array.from({ length: 11 }, (_, i) => `p${i}@ejemplo.com`).join(' '))
    expect(review).toBeDisabled()
    expect(screen.getByText(/Máximo 10 destinatarios/)).toBeInTheDocument()
  })

  it('primero muestra los destinatarios y no envía hasta que se confirma', async () => {
    const user = await open()
    await user.type(screen.getByLabelText('Para'), 'Jefe@Ejemplo.com, dueno@ejemplo.com')
    await user.click(screen.getByRole('button', { name: 'Revisar destinatarios' }))

    const list = screen.getByRole('region', { name: 'Destinatarios' })
    expect(within(list).getByText('jefe@ejemplo.com')).toBeInTheDocument()
    expect(within(list).getByText('dueno@ejemplo.com')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Enviar ahora' })).toBeDisabled()
    expect(calls.some((c) => c.url.includes('/email'))).toBe(false)

    await user.click(screen.getByRole('checkbox', { name: /Confirmo que estas personas pueden recibir/ }))
    expect(screen.getByRole('button', { name: 'Enviar ahora' })).toBeEnabled()
  })

  it('al confirmar envía el periodo, el formato, los destinatarios y la confirmación, y muestra el resultado de cada uno', async () => {
    emailResponse = { status: 200, body: { data: { results: [{ to: 'jefe@ejemplo.com', status: 'sent', error: null }, { to: 'dueno@ejemplo.com', status: 'failed', error: 'SMTP caído' }], sent: 1, failed: 1 } } }
    const user = await open()
    await user.type(screen.getByLabelText('Para'), 'jefe@ejemplo.com dueno@ejemplo.com')
    await user.selectOptions(screen.getByLabelText('Archivo adjunto'), 'pdf')
    await user.type(screen.getByLabelText('Mensaje (opcional)'), 'Para el lunes')
    await user.click(screen.getByRole('button', { name: 'Revisar destinatarios' }))
    await user.click(screen.getByRole('checkbox', { name: /Confirmo/ }))
    await user.click(screen.getByRole('button', { name: 'Enviar ahora' }))

    const sent = calls.find((c) => c.url.includes('/reports/sellers/email'))!
    expect(sent.method).toBe('POST')
    expect(sent.body).toMatchObject({ from: '2026-10-01', to: '2026-10-31', format: 'pdf', recipients: ['jefe@ejemplo.com', 'dueno@ejemplo.com'], message: 'Para el lunes', confirmed: true })
    expect(await screen.findByText(/jefe@ejemplo.com/)).toBeInTheDocument()
    const failedRow = screen.getByText(/no se pudo enviar \(SMTP caído\)/).closest('li')!
    expect(within(failedRow).getByText('dueno@ejemplo.com')).toBeInTheDocument()
    expect(screen.getByText(/Un envío falló/)).toBeInTheDocument()
  })

  it('muestra el error del servidor (límite por hora, correo sin configurar) y deja reintentar', async () => {
    emailResponse = { status: 429, body: { message: 'x', errors: { recipients: ['Alcanzaste el máximo de 30 correos de reportes por hora. Inténtalo más tarde.'] } } }
    const user = await open()
    await user.type(screen.getByLabelText('Para'), 'jefe@ejemplo.com')
    await user.click(screen.getByRole('button', { name: 'Revisar destinatarios' }))
    await user.click(screen.getByRole('checkbox', { name: /Confirmo/ }))
    await user.click(screen.getByRole('button', { name: 'Enviar ahora' }))

    expect(await screen.findByText(/Alcanzaste el máximo de 30 correos/)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Enviar ahora' })).toBeEnabled()
  })

  it('se puede volver a corregir los destinatarios después de revisarlos', async () => {
    const user = await open()
    await user.type(screen.getByLabelText('Para'), 'jefe@ejemplo.com')
    await user.click(screen.getByRole('button', { name: 'Revisar destinatarios' }))

    await user.click(screen.getByRole('button', { name: 'Volver' }))

    expect(screen.getByLabelText('Para')).toHaveValue('jefe@ejemplo.com')
  })
})
