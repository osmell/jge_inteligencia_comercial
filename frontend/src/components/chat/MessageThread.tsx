import { useCallback, useEffect, useLayoutEffect, useRef, useState } from 'react'
import { api } from '../../lib/api'
import { dayLabel, mergeMessages, type ChatMessage, type ConversationRow, type MessagesPage } from '../../lib/conversations'
import { onConversationEvent, onRealtimeStatus } from '../../lib/realtime'
import { UNREAD_CHANGED } from '../../lib/useUnread'
import { Alert } from '../ui'
import Composer from './Composer'
import MessageBubble from './MessageBubble'

/** Mensajes de una conversación: los 50 más recientes, «cargar anteriores» hacia atrás, en vivo, con su cuadro de envío. */
export default function MessageThread({ conversation, onRead }: { conversation: ConversationRow; onRead: () => void }) {
  const id = conversation.id
  const [messages, setMessages] = useState<ChatMessage[]>([])
  const [hasMore, setHasMore] = useState(false)
  const [windowOpen, setWindowOpen] = useState(conversation.window_open)
  const [canReply, setCanReply] = useState(conversation.can_reply)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [replyTo, setReplyTo] = useState<ChatMessage | null>(null)
  const box = useRef<HTMLDivElement>(null)
  const stick = useRef(true)                       // seguir el final solo si la persona ya estaba abajo
  const keep = useRef<number | null>(null)         // altura previa al cargar mensajes anteriores
  const lastRead = useRef(0)

  const markRead = useCallback(() => {
    api(`/conversations/${id}/read`, { method: 'POST' }).then(() => { onRead(); window.dispatchEvent(new Event(UNREAD_CHANGED)) }).catch(() => undefined)
  }, [id, onRead])

  const loadLatest = useCallback(async (silent: boolean) => {
    if (!silent) setLoading(true)
    try {
      const r = await api<MessagesPage>(`/conversations/${id}/messages`)
      setMessages((cur) => (silent ? mergeMessages(cur, r.data) : r.data))
      if (!silent) setHasMore(r.meta.has_more)
      setWindowOpen(r.meta.window_open)
      setCanReply(r.meta.can_reply)
      setError(null)
    } catch (e) {
      setError(e instanceof Error ? e.message : 'No se pudieron cargar los mensajes.')
    } finally {
      setLoading(false)
    }
  }, [id])

  // El hilo se monta de nuevo por cada conversación (key), así que parte siempre de cero.
  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void loadLatest(false)
  }, [loadLatest])

  async function loadOlder() {
    const oldest = messages[0]?.id
    if (!oldest) return
    try {
      const r = await api<MessagesPage>(`/conversations/${id}/messages?before=${oldest}`)
      keep.current = box.current?.scrollHeight ?? null
      setMessages((cur) => mergeMessages(r.data, cur))
      setHasMore(r.meta.has_more)
    } catch (e) {
      setError(e instanceof Error ? e.message : 'No se pudieron cargar los mensajes anteriores.')
    }
  }

  // Mantiene la posición al agregar mensajes anteriores y baja al final cuando corresponde.
  useLayoutEffect(() => {
    const el = box.current
    if (!el) return
    if (keep.current !== null) {
      el.scrollTop += el.scrollHeight - keep.current
      keep.current = null
    } else if (stick.current) {
      el.scrollTop = el.scrollHeight
    }
  }, [messages])

  // Marca como leído hasta el último mensaje del cliente que se ve.
  useEffect(() => {
    const last = [...messages].reverse().find((m) => m.direction === 'in')?.id ?? 0
    if (last > lastRead.current) {
      lastRead.current = last
      markRead()
    }
  }, [messages, markRead])

  // Tiempo real: mensajes nuevos y cambios de estado de esta conversación; sin conexión, se consulta cada 10 segundos.
  useEffect(() => onConversationEvent((e) => {
    if (e.conversation.id !== id) return
    setMessages((cur) => mergeMessages(cur, [e.message]))
    setWindowOpen(e.conversation.window_open)
  }), [id])
  useEffect(() => {
    let timer: ReturnType<typeof setInterval> | undefined
    const off = onRealtimeStatus((s) => {
      clearInterval(timer)
      if (s !== 'connected') timer = setInterval(() => void loadLatest(true), 10000)
    })
    return () => {
      off()
      clearInterval(timer)
    }
  }, [loadLatest])

  async function retry(m: ChatMessage) {
    try {
      const r = await api<{ data: ChatMessage }>(`/conversations/messages/${m.id}/retry`, { method: 'POST' })
      setMessages((cur) => mergeMessages(cur, [r.data]))
    } catch (e) {
      setError(e instanceof Error ? e.message : 'No se pudo reenviar el mensaje.')
    }
  }

  function sent(m: ChatMessage) {
    stick.current = true
    setMessages((cur) => mergeMessages(cur, [m]))
  }

  const live: ConversationRow = { ...conversation, can_reply: canReply, window_open: windowOpen }

  return (
    <div className="flex h-full min-h-0 flex-col bg-surface-muted">
      <div ref={box} className="min-h-0 flex-1 space-y-1.5 overflow-y-auto p-4" role="log" aria-label="Mensajes de la conversación" aria-live="polite"
        onScroll={(e) => { const el = e.currentTarget; stick.current = el.scrollHeight - el.scrollTop - el.clientHeight < 80 }}>
        {error && <Alert tone="danger">{error}</Alert>}
        {hasMore && <div className="pb-2 text-center"><button type="button" onClick={() => void loadOlder()} className="cursor-pointer rounded-full border border-line-strong bg-surface px-3 py-1 text-xs text-jge-blue hover:bg-jge-blue-50">Cargar mensajes anteriores</button></div>}
        {loading && messages.length === 0 && <p className="text-center text-sm text-ink-muted">Cargando mensajes…</p>}
        {!loading && !error && messages.length === 0 && <p className="text-center text-sm text-ink-muted">Todavía no hay mensajes en esta conversación.</p>}
        {messages.map((m, i) => {
          const label = dayLabel(m.created_at)
          const header = i === 0 || dayLabel(messages[i - 1]!.created_at) !== label
          return (
            <div key={m.id} className="space-y-1.5">
              {header && <p className="sticky top-0 mx-auto w-fit rounded-full bg-surface px-3 py-0.5 text-center text-[11px] font-medium text-ink-muted shadow-sm">{label}</p>}
              <MessageBubble m={m} canReply={canReply && windowOpen} onReply={setReplyTo} onRetry={(x) => void retry(x)} />
            </div>
          )
        })}
      </div>
      <Composer conversation={live} windowOpen={windowOpen} replyTo={replyTo} onClearReply={() => setReplyTo(null)} onSent={sent} />
    </div>
  )
}
