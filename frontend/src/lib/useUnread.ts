import { useEffect, useState } from 'react'
import { api } from './api'
import { onConversationEvent } from './realtime'

export const UNREAD_CHANGED = 'jge:unread-changed'

/** Conversaciones con mensajes sin leer (insignia del menú): al cargar, cada minuto, al llegar un mensaje y al leer una conversación. */
export function useUnreadConversations(enabled: boolean): number {
  const [count, setCount] = useState(0)
  useEffect(() => {
    if (!enabled) return
    let alive = true
    let timer: ReturnType<typeof setTimeout> | undefined
    const load = () => api<{ data: { conversations: number } }>('/conversations/unread').then((r) => alive && setCount(r.data.conversations)).catch(() => undefined)
    const soon = () => {
      clearTimeout(timer)
      timer = setTimeout(() => void load(), 600)
    }
    void load()
    const poll = setInterval(() => void load(), 60000)
    const off = onConversationEvent((e) => e.kind === 'created' && soon())
    window.addEventListener(UNREAD_CHANGED, soon)
    return () => {
      alive = false
      clearTimeout(timer)
      clearInterval(poll)
      off()
      window.removeEventListener(UNREAD_CHANGED, soon)
    }
  }, [enabled])
  return enabled ? count : 0
}
