import { ArrowLeft, MessageCircle } from 'lucide-react'
import { useCallback, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import ConversationList from '../components/chat/ConversationList'
import MessageThread from '../components/chat/MessageThread'
import SidePanel from '../components/chat/SidePanel'
import { useConversationList, type ListTab } from '../components/chat/useConversationList'
import { conversationTitle, type ConversationRow } from '../lib/conversations'
import { useAuth } from '../auth/AuthContext'
import { Alert } from '../components/ui'

/** Conversaciones de WhatsApp con los clientes, al estilo de WhatsApp Web: lista, mensajes y datos del cliente. */
export default function ConversationsPage() {
  const { can } = useAuth()
  const [params, setParams] = useSearchParams()
  const selectedId = Number(params.get('c')) || null
  const [tab, setTab] = useState<ListTab>('open')
  const [query, setQuery] = useState('')
  const list = useConversationList(tab, query, selectedId)
  const [opened, setOpened] = useState<ConversationRow | null>(null)
  // La conversación abierta se toma de la lista; si llegó por un enlace y no está en la página cargada, se conserva la última vista.
  const selected = list.rows.find((r) => r.id === selectedId) ?? (opened?.id === selectedId ? opened : null)
  const { clearUnread } = list

  const open = (c: ConversationRow) => {
    setOpened(c)
    setParams({ c: String(c.id) })
  }
  const onRead = useCallback(() => {
    if (selectedId) clearUnread(selectedId)
  }, [selectedId, clearUnread])

  return (
    <div>
      <h1 className="sr-only">Conversaciones</h1>
      {!can('conversations.reply') && <div className="mb-3"><Alert tone="info">Tienes acceso de solo lectura: puedes ver las conversaciones, pero no escribir a los clientes.</Alert></div>}
      <div className="grid h-[calc(100dvh-11rem)] min-h-[30rem] grid-cols-1 overflow-hidden rounded-xl border border-line bg-surface md:grid-cols-[20rem_1fr] lg:grid-cols-[20rem_1fr_18rem]">
        <div className={`min-h-0 ${selected ? 'hidden md:block' : ''}`}>
          <ConversationList rows={list.rows} loading={list.loading} error={list.error} hasMore={list.hasMore} onMore={() => void list.more()}
            selectedId={selectedId} onSelect={open} tab={tab} onTab={setTab} query={query} onQuery={setQuery} />
        </div>
        {selected ? (
          <>
            <section aria-label={`Conversación con ${conversationTitle(selected)}`} className={`flex min-h-0 flex-col`}>
              <header className="flex items-center gap-3 border-b border-line bg-surface px-4 py-3">
                <button type="button" onClick={() => setParams({})} aria-label="Volver a la lista de conversaciones" className="flex h-9 w-9 cursor-pointer items-center justify-center rounded-lg border border-line-strong text-ink md:hidden"><ArrowLeft className="h-4 w-4" aria-hidden="true" /></button>
                <div className="min-w-0">
                  <p className="truncate text-sm font-semibold text-ink">{conversationTitle(selected)}</p>
                  <p className="tabular truncate text-xs text-ink-muted">+{selected.wa_number}</p>
                </div>
              </header>
              <div className="min-h-0 flex-1"><MessageThread key={selected.id} conversation={selected} onRead={onRead} /></div>
            </section>
            <div className="hidden min-h-0 lg:block"><SidePanel key={selected.id} conversation={selected} /></div>
          </>
        ) : (
          <div className="hidden flex-col items-center justify-center gap-2 bg-surface-muted p-8 text-center md:flex lg:col-span-2">
            <MessageCircle className="h-10 w-10 text-ink-subtle" aria-hidden="true" />
            <p className="text-sm font-medium text-ink">Elige una conversación</p>
            <p className="text-xs text-ink-muted">Los mensajes nuevos de tus clientes aparecen aquí al instante.</p>
          </div>
        )}
      </div>
    </div>
  )
}
