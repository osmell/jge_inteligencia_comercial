import { useCallback, useEffect, useRef, useState } from 'react'
import { api } from '../../lib/api'
import type { ConversationRow } from '../../lib/conversations'
import { onConversationEvent, onRealtimeStatus } from '../../lib/realtime'

export type ListTab = 'open' | 'unassigned' | 'archived'

/** Lista de conversaciones con pestañas, búsqueda y «cargar más»; se mantiene al día con el tiempo real (o por consultas si cae). */
export function useConversationList(tab: ListTab, query: string, selectedId: number | null) {
  const [rows, setRows] = useState<ConversationRow[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [hasMore, setHasMore] = useState(false)
  const page = useRef(1)
  const selected = useRef(selectedId)
  useEffect(() => {
    selected.current = selectedId
  }, [selectedId])

  const url = useCallback((p: number) => {
    const params = new URLSearchParams({ page: String(p), status: tab === 'archived' ? 'archived' : 'open' })
    if (tab === 'unassigned') params.set('unassigned', '1')
    if (query.trim()) params.set('q', query.trim())
    return `/conversations?${params.toString()}`
  }, [tab, query])

  const load = useCallback(async (silent: boolean) => {
    if (!silent) setLoading(true)
    try {
      const r = await api<{ data: ConversationRow[]; meta: { has_more: boolean } }>(url(1))
      page.current = 1
      setRows(r.data)
      setHasMore(r.meta.has_more)
      setError(null)
    } catch (e) {
      setError(e instanceof Error ? e.message : 'No se pudieron cargar las conversaciones.')
    } finally {
      setLoading(false)
    }
  }, [url])

  useEffect(() => {
    // Con búsqueda, espera a que la persona termine de escribir.
    const t = setTimeout(() => void load(false), query ? 300 : 0)
    return () => clearTimeout(t)
  }, [load, query])

  async function more() {
    try {
      const r = await api<{ data: ConversationRow[]; meta: { has_more: boolean } }>(url(page.current + 1))
      page.current += 1
      setRows((cur) => [...cur, ...r.data.filter((n) => !cur.some((c) => c.id === n.id))])
      setHasMore(r.meta.has_more)
    } catch (e) {
      setError(e instanceof Error ? e.message : 'No se pudieron cargar más conversaciones.')
    }
  }

  // Tiempo real: actualiza la fila, la sube al inicio y cuenta el no leído; si la conversación no está en la lista, la recarga.
  useEffect(() => {
    return onConversationEvent((e) => {
      const known = rows.some((r) => r.id === e.conversation.id)
      if (!known) {
        if (e.kind === 'created') void load(true)
        return
      }
      setRows((cur) => {
        const current = cur.find((r) => r.id === e.conversation.id)
        if (!current) return cur
        const bump = e.kind === 'created' && e.message.direction === 'in' && e.message.type !== 'reaction' && selected.current !== current.id ? 1 : 0
        const next = { ...current, ...e.conversation, unread: current.unread + bump, can_reply: current.can_reply }
        return e.kind === 'created' ? [next, ...cur.filter((r) => r.id !== current.id)] : cur.map((r) => (r.id === current.id ? next : r))
      })
    })
  }, [rows, load])

  // Respaldo: sin conexión de tiempo real, se consulta cada 15 segundos.
  useEffect(() => {
    let timer: ReturnType<typeof setInterval> | undefined
    const off = onRealtimeStatus((s) => {
      clearInterval(timer)
      if (s !== 'connected') timer = setInterval(() => void load(true), 15000)
    })
    return () => {
      off()
      clearInterval(timer)
    }
  }, [load])

  const clearUnread = useCallback((id: number) => setRows((cur) => cur.map((r) => (r.id === id && r.unread > 0 ? { ...r, unread: 0 } : r))), [])

  const patch = useCallback((id: number, changes: Partial<ConversationRow>) => setRows((cur) => cur.map((r) => (r.id === id ? { ...r, ...changes } : r))), [])

  return { rows, patch, loading, error, hasMore, more, reload: () => load(false), clearUnread }
}
