import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import { api } from './api'

const API_URL = (import.meta.env.VITE_API_URL as string | undefined) ?? 'http://localhost:8000/api/v1'

export type RealtimeConfig = { key: string; host: string; port: number; scheme: string }

export type ConversationSummary = {
  id: number
  company_id: number
  customer: { id: number; code: string; name: string } | null
  wa_number: string
  profile_name: string | null
  status: 'open' | 'archived'
  preview: string | null
  last_message_at: string | null
  window_open: boolean
}

export type LiveMessage = {
  id: number
  direction: 'in' | 'out'
  type: string
  body: string
  status: string
  error: string | null
  author: string | null
  created_at: string
  delivered_at: string | null
  read_at: string | null
  reply_to: { id: number; direction: 'in' | 'out'; body: string } | null
  attachments: { id: number; kind: string; name: string | null; mime: string | null; size: number | null; status: string; error: string | null }[]
}

export type ConversationEvent = { kind: 'created' | 'updated'; conversation: ConversationSummary; message: LiveMessage }

type Status = 'off' | 'connecting' | 'connected' | 'disconnected'

const conversationHandlers = new Set<(e: ConversationEvent) => void>()
const noticeHandlers = new Set<(type: string) => void>()
const statusHandlers = new Set<(s: Status) => void>()
let status: Status = 'off'
let echo: Echo<'reverb'> | null = null

function setStatus(next: Status) {
  status = next
  statusHandlers.forEach((h) => h(next))
}

function readCookie(name: string): string | null {
  const match = document.cookie.split('; ').find((c) => c.startsWith(`${name}=`))
  return match ? decodeURIComponent(match.slice(name.length + 1)) : null
}

/** Autoriza los canales privados con la sesión de la persona (cookie de Sanctum), igual que el resto de la API. */
function authorizer(channel: { name: string }) {
  return {
    authorize: (socketId: string, callback: (error: Error | null, data: { auth: string } | null) => void) => {
      fetch(`${API_URL}/broadcasting/auth`, {
        method: 'POST',
        credentials: 'include',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': readCookie('XSRF-TOKEN') ?? '' },
        body: JSON.stringify({ socket_id: socketId, channel_name: channel.name }),
      })
        .then(async (r) => {
          if (!r.ok) throw new Error(`Canal no autorizado (${r.status})`)
          callback(null, (await r.json()) as { auth: string })
        })
        .catch((e: Error) => callback(e, null))
    },
  }
}

/**
 * Conecta al servidor de tiempo real y escucha los canales privados de la persona. Devuelve una función que cierra la conexión.
 * Si el tiempo real no está configurado en el servidor, no hace nada: la aplicación sigue funcionando con consultas periódicas.
 */
export async function startRealtime(userId: number, conversations: boolean): Promise<() => void> {
  let config: RealtimeConfig | null = null
  try {
    const r = await api<{ data: RealtimeConfig | null }>('/realtime/config')
    config = r.data && typeof r.data.key === 'string' && typeof r.data.host === 'string' ? r.data : null
  } catch {
    config = null
  }
  if (!config) return () => undefined

  setStatus('connecting')
  ;(window as unknown as { Pusher: typeof Pusher }).Pusher = Pusher
  const client = new Echo({
    broadcaster: 'reverb',
    key: config.key,
    wsHost: config.host,
    wsPort: config.port,
    wssPort: config.port,
    forceTLS: config.scheme === 'https',
    enabledTransports: ['ws', 'wss'],
    authorizer,
  })
  echo = client
  const connection = (client.connector as unknown as { pusher: { connection: { bind: (e: string, cb: () => void) => void } } }).pusher.connection
  connection.bind('connected', () => setStatus('connected'))
  connection.bind('disconnected', () => setStatus('disconnected'))
  connection.bind('unavailable', () => setStatus('disconnected'))

  client.private(`users.${userId}.notifications`).listen('.notice.created', (e: { type: string }) => noticeHandlers.forEach((h) => h(e.type)))
  if (conversations) {
    const channel = client.private(`users.${userId}.conversations`)
    for (const kind of ['created', 'updated'] as const) {
      channel.listen(`.message.${kind}`, (e: Omit<ConversationEvent, 'kind'>) => conversationHandlers.forEach((h) => h({ ...e, kind })))
    }
  }

  return () => {
    client.disconnect()
    if (echo === client) echo = null
    setStatus('off')
  }
}

export function onConversationEvent(handler: (e: ConversationEvent) => void): () => void {
  conversationHandlers.add(handler)
  return () => void conversationHandlers.delete(handler)
}

export function onNotice(handler: (type: string) => void): () => void {
  noticeHandlers.add(handler)
  return () => void noticeHandlers.delete(handler)
}

export function onRealtimeStatus(handler: (s: Status) => void): () => void {
  statusHandlers.add(handler)
  handler(status)
  return () => void statusHandlers.delete(handler)
}

/** Para pruebas: ¿hay una conexión abierta? */
export function realtimeConnected(): boolean {
  return echo !== null
}
