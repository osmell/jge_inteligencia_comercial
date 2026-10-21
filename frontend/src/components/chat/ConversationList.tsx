import { Search } from 'lucide-react'
import { conversationTitle, formatChatTime, type ConversationRow } from '../../lib/conversations'
import { Alert } from '../ui'
import type { ListTab } from './useConversationList'

const TABS: { id: ListTab; label: string }[] = [
  { id: 'open', label: 'Abiertas' },
  { id: 'unassigned', label: 'Sin asignar' },
  { id: 'archived', label: 'Archivadas' },
]

export default function ConversationList({
  rows, loading, error, hasMore, onMore, selectedId, onSelect, tab, onTab, query, onQuery,
}: {
  rows: ConversationRow[]
  loading: boolean
  error: string | null
  hasMore: boolean
  onMore: () => void
  selectedId: number | null
  onSelect: (c: ConversationRow) => void
  tab: ListTab
  onTab: (t: ListTab) => void
  query: string
  onQuery: (q: string) => void
}) {
  return (
    <section aria-label="Conversaciones" className="flex h-full min-h-0 flex-col border-line bg-surface md:border-r">
      <div className="space-y-2 border-b border-line p-3">
        <div className="relative">
          <label htmlFor="chat-search" className="sr-only">Buscar conversación por cliente o número</label>
          <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-subtle" aria-hidden="true" />
          <input id="chat-search" type="search" value={query} onChange={(e) => onQuery(e.target.value)} placeholder="Buscar cliente o número"
            className="min-h-10 w-full rounded-lg border border-line-strong bg-surface pl-9 pr-3 text-sm text-ink focus:border-jge-blue focus:outline-none focus:ring-2 focus:ring-jge-blue/20" />
        </div>
        <div role="tablist" aria-label="Estado de las conversaciones" className="flex gap-1">
          {TABS.map((t) => (
            <button key={t.id} role="tab" type="button" aria-selected={tab === t.id} onClick={() => onTab(t.id)}
              className={`min-h-9 flex-1 cursor-pointer rounded-lg px-2 text-xs font-medium ${tab === t.id ? 'bg-jge-blue text-white' : 'text-ink-muted hover:bg-surface-muted'}`}>
              {t.label}
            </button>
          ))}
        </div>
      </div>
      {error && <div className="p-3"><Alert tone="danger">{error}</Alert></div>}
      <ul className="min-h-0 flex-1 divide-y divide-line overflow-y-auto">
        {loading && rows.length === 0 && <li className="p-4 text-sm text-ink-muted">Cargando conversaciones…</li>}
        {!loading && !error && rows.length === 0 && (
          <li className="p-6 text-center text-sm text-ink-muted">{query ? 'No hay conversaciones que coincidan.' : tab === 'unassigned' ? 'No hay números sin asignar.' : tab === 'archived' ? 'No hay conversaciones archivadas.' : 'Todavía no hay conversaciones.'}</li>
        )}
        {rows.map((c) => {
          const title = conversationTitle(c)
          return (
            <li key={c.id}>
              <button type="button" onClick={() => onSelect(c)} aria-current={selectedId === c.id ? 'true' : undefined}
                aria-label={`${title}${c.unread > 0 ? `, ${c.unread} sin leer` : ''}`}
                className={`flex w-full cursor-pointer items-start gap-3 px-3 py-3 text-left hover:bg-surface-muted ${selectedId === c.id ? 'bg-jge-blue-50' : ''}`}>
                <span className="mt-0.5 flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-jge-blue-50 text-sm font-semibold text-jge-blue" aria-hidden="true">
                  {title.replace(/^\+/, '').slice(0, 1).toUpperCase()}
                </span>
                <span className="min-w-0 flex-1">
                  <span className="flex items-baseline justify-between gap-2">
                    <span className={`truncate text-sm ${c.unread > 0 ? 'font-semibold text-ink' : 'font-medium text-ink'}`}>{title}</span>
                    <span className={`shrink-0 text-[11px] ${c.unread > 0 ? 'font-semibold text-jge-blue' : 'text-ink-subtle'}`}>{formatChatTime(c.last_message_at)}</span>
                  </span>
                  <span className="flex items-center justify-between gap-2">
                    <span className="truncate text-xs text-ink-muted">{c.preview ?? 'Sin mensajes'}</span>
                    {c.unread > 0 && <span className="tabular flex min-w-5 shrink-0 items-center justify-center rounded-full bg-jge-red px-1.5 text-[11px] font-semibold leading-5 text-white">{c.unread > 99 ? '99+' : c.unread}</span>}
                  </span>
                  {!c.customer && <span className="mt-0.5 inline-block rounded bg-warning/15 px-1.5 text-[11px] font-medium text-ink">Sin asignar</span>}
                </span>
              </button>
            </li>
          )
        })}
        {hasMore && <li className="p-3 text-center"><button type="button" onClick={onMore} className="cursor-pointer text-sm text-jge-blue hover:underline">Cargar más conversaciones</button></li>}
      </ul>
    </section>
  )
}
