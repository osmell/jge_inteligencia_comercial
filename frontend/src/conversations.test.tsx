import { act, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { AuthProvider } from './auth/AuthContext'
import ConversationsPage from './pages/ConversationsPage'
import type { ConversationRow, ChatMessage } from './lib/conversations'
import { startRealtime } from './lib/realtime'

const channels: Record<string, Record<string, (e: unknown) => void>> = {}
vi.mock('laravel-echo', () => ({
  default: class {
    connector = { pusher: { connection: { bind: () => undefined } } }
    private(name: string) {
      const handlers: Record<string, (e: unknown) => void> = (channels[name] = {})
      return { listen(event: string, cb: (e: unknown) => void) { handlers[event] = cb; return this } }
    }
    disconnect() {}
  },
}))
vi.mock('pusher-js', () => ({ default: class {} }))

type Call = { path: string; method: string; body: unknown }
let calls: Call[]
let rows: ConversationRow[]
let messages: Record<number, ChatMessage[]>
let more: boolean
let realtime: boolean

const row = (o: Partial<ConversationRow>): ConversationRow => ({
  id: 1, company_id: 3, customer: { id: 10, code: 'C-10', name: 'Ferretería Lupita' }, wa_number: '50370001111', profile_name: 'Lupita', status: 'open', preview: 'Hola, ¿tienen cemento?',
  last_message_at: new Date().toISOString(), window_open: true, unread: 2, can_reply: true, ...o,
})
const msg = (o: Partial<ChatMessage>): ChatMessage => ({
  id: 1, direction: 'in', type: 'text', body: 'Hola, ¿tienen cemento?', status: 'received', error: null, author: null, created_at: new Date().toISOString(), delivered_at: null, read_at: null, reply_to: null, attachments: [], ...o,
})

beforeEach(() => {
  document.cookie = 'XSRF-TOKEN=x'
  for (const k of Object.keys(channels)) delete channels[k]
  calls = []
  more = false
  realtime = false
  rows = [row({}), row({ id: 2, customer: null, profile_name: null, wa_number: '50370009999', preview: 'Quiero cotizar', unread: 0, can_reply: false })]
  messages = {
    1: [
      msg({ id: 1 }),
      msg({ id: 2, direction: 'out', status: 'read', body: 'Sí, hay disponibilidad', author: 'Vera', reply_to: { id: 1, direction: 'in', body: 'Hola, ¿tienen cemento?' } }),
      msg({ id: 3, direction: 'in', type: 'image', body: 'Mira esta foto', attachments: [{ id: 7, kind: 'image', name: 'foto.jpg', mime: 'image/jpeg', size: 1000, status: 'stored', error: null }] }),
      msg({ id: 4, direction: 'in', type: 'document', body: '', attachments: [{ id: 8, kind: 'document', name: 'lista.pdf', mime: 'application/pdf', size: 2048, status: 'stored', error: null }] }),
    ],
    2: [msg({ id: 9, body: 'Quiero cotizar' })],
  }
  vi.stubGlobal('fetch', vi.fn(async (url: string, init?: RequestInit) => {
    const path = url.replace('http://localhost:8000/api/v1', '')
    const method = init?.method ?? 'GET'
    const raw = init?.body
    calls.push({ path, method, body: raw instanceof FormData ? Object.fromEntries(raw.entries()) : raw ? JSON.parse(String(raw)) : undefined })
    let body: unknown = { data: [] }
    let status = 200
    if (path === '/auth/me') body = { data: { id: 1, username: 'vera', name: 'Vera', role: { slug: 'vendedor', name: 'Vendedor' }, permissions: ['conversations.view', 'conversations.reply'], companies: [], branches: [] } }
    else if (path === '/realtime/config') body = { data: realtime ? { key: 'k', host: 'localhost', port: 8080, scheme: 'http' } : null }
    else if (path.startsWith('/conversations?')) body = { data: rows, meta: { page: 1, has_more: false } }
    else if (path === '/conversations/templates') body = { data: [{ id: 5, name: 'seguimiento', body: 'Hola {{1}}, ¿seguimos con tu pedido?', variables: 1 }] }
    else if (/^\/conversations\/\d+\/messages/.test(path) && method === 'GET') {
      const id = Number(path.split('/')[2])
      const before = /before=(\d+)/.exec(path)
      body = before
        ? { data: [msg({ id: 0, body: 'Mensaje antiguo' })], meta: { has_more: false, oldest_id: 0, window_open: true, can_reply: true } }
        : { data: messages[id] ?? [], meta: { has_more: more, oldest_id: messages[id]?.[0]?.id ?? null, window_open: rows.find((r) => r.id === id)?.window_open ?? true, can_reply: rows.find((r) => r.id === id)?.can_reply ?? false } }
    } else if (/\/messages$/.test(path) && method === 'POST') {
      body = { data: msg({ id: 50, direction: 'out', status: 'sent', body: String((calls.at(-1)!.body as Record<string, string>).body ?? ''), author: 'Vera' }) }
      status = 201
    } else if (/\/template$/.test(path)) {
      body = { data: msg({ id: 51, direction: 'out', type: 'template', status: 'sent', body: 'Hola Lupita, ¿seguimos con tu pedido?' }) }
      status = 201
    } else if (/\/retry$/.test(path)) body = { data: msg({ id: 4, direction: 'out', status: 'sent', body: 'Reenviado' }) }
    else if (/\/read$/.test(path)) body = { data: { last_read_message_id: 4 } }
    else if (/\/notes$/.test(path) && method === 'POST') { body = { data: { id: 2, body: 'Pidió factura a su nombre', author: 'Vera', created_at: new Date().toISOString() } }; status = 201 }
    else if (/\/notes$/.test(path)) body = { data: [{ id: 1, body: 'Paga de contado', author: 'Beto', created_at: new Date().toISOString() }] }
    else if (path === '/conversations/unread') body = { data: { conversations: 1 } }
    return new Response(JSON.stringify(body), { status })
  }))
})
afterEach(() => vi.unstubAllGlobals())

const renderPage = (at = '/conversaciones') => render(<AuthProvider><MemoryRouter initialEntries={[at]}><ConversationsPage /></MemoryRouter></AuthProvider>)
const open = async (name: RegExp) => userEvent.setup().click(await screen.findByRole('button', { name }))

describe('conversaciones', () => {
  it('lista las conversaciones con sus no leídos y las que no tienen cliente', async () => {
    renderPage()

    const first = await screen.findByRole('button', { name: /Ferretería Lupita, 2 sin leer/ })
    expect(first).toHaveTextContent('Hola, ¿tienen cemento?')
    expect(screen.getByRole('button', { name: /\+50370009999/ })).toHaveTextContent('Sin asignar')
  })

  it('al abrir una conversación muestra los mensajes con sus estados, citas y archivos, la marca como leída y muestra notas', async () => {
    renderPage()
    await open(/Ferretería Lupita/)

    const log = await screen.findByRole('log', { name: 'Mensajes de la conversación' })
    expect(await within(log).findByText('Sí, hay disponibilidad')).toBeInTheDocument()
    expect(within(log).getByText('Leído')).toBeInTheDocument()                                        // palomitas azules, también en texto
    expect(within(log).getByText('Cliente:', { exact: false })).toBeInTheDocument()                   // la cita
    expect(within(log).getByRole('img', { name: 'foto.jpg' })).toHaveAttribute('src', 'http://localhost:8000/api/v1/conversations/attachments/7')
    expect(within(log).getByRole('link', { name: /lista\.pdf/ })).toHaveAttribute('href', expect.stringContaining('/attachments/8?inline=0'))
    await waitFor(() => expect(calls.some((c) => c.path === '/conversations/1/read' && c.method === 'POST')).toBe(true))
    await waitFor(() => expect(screen.getByRole('button', { name: /Ferretería Lupita$/ })).toBeInTheDocument())   // ya sin contador de no leídos
    const panel = screen.getByRole('complementary', { name: 'Datos del cliente' })
    expect(await within(panel).findByText('Paga de contado')).toBeInTheDocument()
    expect(within(panel).getByRole('link', { name: /Ver ficha del cliente/ })).toHaveAttribute('href', '/clientes/ficha?codigo=C-10&compania=3')
  })

  it('envía con Enter, con una clave contra duplicados, y el mensaje queda en la conversación', async () => {
    const u = userEvent.setup()
    renderPage()
    await open(/Ferretería Lupita/)

    await u.type(await screen.findByLabelText('Mensaje'), 'Claro, te cotizo hoy{Enter}')

    await waitFor(() => expect(calls.some((c) => c.path === '/conversations/1/messages' && c.method === 'POST')).toBe(true))
    const post = calls.find((c) => c.method === 'POST' && c.path === '/conversations/1/messages')!.body as Record<string, string>
    expect(post.body).toBe('Claro, te cotizo hoy')
    expect(post.client_key).toMatch(/^[0-9a-f-]{36}$/)
    expect(await screen.findByText('Claro, te cotizo hoy')).toBeInTheDocument()
    expect(screen.getByLabelText('Mensaje')).toHaveValue('')
  })

  it('permite citar un mensaje al responder', async () => {
    const u = userEvent.setup()
    renderPage()
    await open(/Ferretería Lupita/)
    await screen.findByText('Mira esta foto')

    await u.click(screen.getAllByRole('button', { name: 'Responder a este mensaje' })[2]!)
    expect(screen.getByText(/Respondiendo a el cliente/)).toBeInTheDocument()
    await u.type(screen.getByLabelText('Mensaje'), 'Linda foto{Enter}')

    await waitFor(() => expect(calls.some((c) => c.method === 'POST' && c.path === '/conversations/1/messages')).toBe(true))
    expect((calls.find((c) => c.method === 'POST' && c.path === '/conversations/1/messages')!.body as Record<string, string>).reply_to_id).toBe('3')
  })

  it('un archivo que supera el máximo de WhatsApp se rechaza sin enviarlo', async () => {
    const u = userEvent.setup()
    renderPage()
    await open(/Ferretería Lupita/)
    await screen.findByLabelText('Mensaje')

    const big = new File([new Uint8Array(6 * 1024 * 1024)], 'foto.png', { type: 'image/png' })
    await u.upload(screen.getByLabelText('Archivo adjunto'), big)

    expect(await screen.findByText(/supera el máximo de WhatsApp para imagen \(5 MB\)/)).toBeInTheDocument()
    expect(calls.some((c) => c.method === 'POST' && c.path.endsWith('/messages'))).toBe(false)

    await u.upload(screen.getByLabelText('Archivo adjunto'), new File(['%PDF'], 'cotizacion.pdf', { type: 'application/pdf' }))
    expect(screen.getByText('cotizacion.pdf')).toBeInTheDocument()
    await u.click(screen.getByRole('button', { name: 'Enviar mensaje' }))
    await waitFor(() => expect(calls.some((c) => c.method === 'POST' && c.path === '/conversations/1/messages')).toBe(true))
    expect((calls.find((c) => c.method === 'POST' && c.path === '/conversations/1/messages')!.body as Record<string, unknown>).file).toBeInstanceOf(File)
  })

  it('con la ventana de 24 horas cerrada solo ofrece plantillas aprobadas', async () => {
    rows = [row({ window_open: false })]
    const u = userEvent.setup()
    renderPage()
    await open(/Ferretería Lupita/)

    expect(await screen.findByText(/Pasaron más de 24 horas/)).toBeInTheDocument()
    expect(screen.queryByLabelText('Mensaje')).not.toBeInTheDocument()
    await u.selectOptions(await screen.findByLabelText('Plantilla'), 'seguimiento')
    expect(screen.getByRole('button', { name: 'Enviar plantilla' })).toBeDisabled()                  // falta el valor de la variable
    await u.type(screen.getByLabelText(/Valor para \{\{1\}\}/), 'Lupita')
    expect(screen.getByLabelText('Vista previa de la plantilla')).toHaveTextContent('Hola Lupita, ¿seguimos con tu pedido?')
    await u.click(screen.getByRole('button', { name: 'Enviar plantilla' }))

    await waitFor(() => expect(calls.find((c) => c.path === '/conversations/1/template')?.body).toEqual({ template_id: 5, params: ['Lupita'] }))
    expect(await within(screen.getByRole('log')).findByText('Hola Lupita, ¿seguimos con tu pedido?')).toBeInTheDocument()
  })

  it('en solo lectura no hay cuadro de envío y lo explica', async () => {
    rows = [row({ can_reply: false })]
    renderPage()
    await open(/Ferretería Lupita/)

    expect(await screen.findByText(/modo solo lectura/)).toBeInTheDocument()
    expect(screen.queryByLabelText('Mensaje')).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Responder a este mensaje' })).not.toBeInTheDocument()
  })

  it('un número sin cliente no se puede contestar hasta asignarlo', async () => {
    renderPage()
    await open(/\+50370009999/)

    expect(await screen.findByText(/no está asignado a un cliente/)).toBeInTheDocument()
  })

  it('carga mensajes anteriores hacia atrás y reenvía los que fallaron', async () => {
    more = true
    messages[1] = [msg({ id: 4, direction: 'out', status: 'failed', body: 'No llegó', error: 'Meta respondió: parámetro inválido', author: 'Vera' })]
    const u = userEvent.setup()
    renderPage()
    await open(/Ferretería Lupita/)

    await u.click(await screen.findByRole('button', { name: 'Cargar mensajes anteriores' }))
    expect(await screen.findByText('Mensaje antiguo')).toBeInTheDocument()
    expect(calls.some((c) => c.path === '/conversations/1/messages?before=4')).toBe(true)

    await u.click(screen.getByRole('button', { name: /Reintentar/ }))
    await waitFor(() => expect(calls.some((c) => c.path === '/conversations/messages/4/retry' && c.method === 'POST')).toBe(true))
    expect(await screen.findByText('Reenviado')).toBeInTheDocument()
  })

  it('en vivo: el mensaje nuevo aparece en la conversación abierta y suma no leídos en las demás', async () => {
    realtime = true
    rows = [row({ unread: 0 }), row({ id: 2, customer: { id: 11, code: 'C-11', name: 'Taller Beto' }, wa_number: '50370002222', preview: 'Antes', unread: 0 })]
    renderPage()
    await open(/Ferretería Lupita/)
    await screen.findByText('Mira esta foto')
    await act(async () => { await startRealtime(1, true) })

    const conversation = (id: number, name: string) => ({ id, company_id: 3, customer: { id: 10 + id, code: `C-${id}`, name }, wa_number: '1', profile_name: null, status: 'open', preview: 'Nuevo mensaje', last_message_at: new Date().toISOString(), window_open: true })
    act(() => channels['users.1.conversations']!['.message.created']!({ conversation: conversation(1, 'Ferretería Lupita'), message: msg({ id: 20, body: 'Me urge el pedido' }) }))
    expect(await screen.findByText('Me urge el pedido')).toBeInTheDocument()

    act(() => channels['users.1.conversations']!['.message.created']!({ conversation: conversation(2, 'Taller Beto'), message: msg({ id: 21, body: 'Buenas tardes' }) }))
    expect(await screen.findByRole('button', { name: /Taller Beto, 1 sin leer/ })).toBeInTheDocument()

    act(() => channels['users.1.conversations']!['.message.updated']!({ conversation: conversation(1, 'Ferretería Lupita'), message: msg({ id: 2, direction: 'out', status: 'delivered', body: 'Sí, hay disponibilidad' }) }))
    expect(await screen.findAllByText('Entregado')).not.toHaveLength(0)
  })
})
