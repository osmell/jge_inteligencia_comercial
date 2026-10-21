import { ExternalLink } from 'lucide-react'
import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../../lib/api'
import { conversationTitle, type ConversationRow, type Note } from '../../lib/conversations'
import { customerLink } from '../../lib/dashboard'
import { formatDateTime } from '../../lib/format'
import { Alert, Button } from '../ui'

/** Datos del cliente y notas internas del equipo (nunca se envían al cliente). */
export default function SidePanel({ conversation }: { conversation: ConversationRow }) {
  const [notes, setNotes] = useState<Note[]>([])
  const [text, setText] = useState('')
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const id = conversation.id

  const load = useCallback(async () => {
    try {
      const r = await api<{ data: Note[] }>(`/conversations/${id}/notes`)
      setNotes(r.data)
    } catch (e) {
      setError(e instanceof Error ? e.message : 'No se pudieron cargar las notas.')
    }
  }, [id])
  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void load()
  }, [load])

  async function add() {
    if (!text.trim()) return
    setSaving(true)
    setError(null)
    try {
      const r = await api<{ data: Note }>(`/conversations/${id}/notes`, { method: 'POST', body: { body: text.trim() } })
      setNotes((n) => [...n, r.data])
      setText('')
    } catch (e) {
      setError(e instanceof Error ? e.message : 'No se pudo guardar la nota.')
    } finally {
      setSaving(false)
    }
  }

  return (
    <aside aria-label="Datos del cliente" className="flex h-full min-h-0 flex-col overflow-y-auto border-line bg-surface p-4 lg:border-l">
      <h2 className="text-base font-semibold text-ink">{conversationTitle(conversation)}</h2>
      <dl className="mt-3 space-y-2 text-sm">
        <div><dt className="text-xs text-ink-muted">WhatsApp</dt><dd className="tabular text-ink">+{conversation.wa_number}</dd></div>
        {conversation.profile_name && conversation.customer && <div><dt className="text-xs text-ink-muted">Nombre en WhatsApp</dt><dd className="text-ink">{conversation.profile_name}</dd></div>}
        {conversation.customer && <div><dt className="text-xs text-ink-muted">Código de cliente</dt><dd className="text-ink">{conversation.customer.code}</dd></div>}
        <div><dt className="text-xs text-ink-muted">Ventana de 24 horas</dt><dd className={conversation.window_open ? 'text-success' : 'text-warning'}>{conversation.window_open ? 'Abierta: se puede escribir libremente' : 'Cerrada: solo plantillas aprobadas'}</dd></div>
        <div><dt className="text-xs text-ink-muted">Último mensaje</dt><dd className="text-ink">{formatDateTime(conversation.last_message_at)}</dd></div>
      </dl>
      {conversation.customer ? (
        <Link to={customerLink(conversation.company_id, conversation.customer.code)} className="mt-3 inline-flex items-center gap-1.5 text-sm text-jge-blue hover:underline">
          Ver ficha del cliente <ExternalLink className="h-3.5 w-3.5" aria-hidden="true" />
        </Link>
      ) : (
        <div className="mt-3"><Alert tone="warning">Este número no es de ningún cliente registrado. Supervisión puede asignarlo cuando lo identifique.</Alert></div>
      )}

      <h3 className="mt-6 text-sm font-semibold text-ink">Notas internas</h3>
      <p className="text-xs text-ink-muted">Solo las ve el equipo; el cliente nunca las recibe.</p>
      {error && <div className="mt-2"><Alert tone="danger">{error}</Alert></div>}
      <ul className="mt-2 space-y-2">
        {notes.length === 0 && <li className="text-sm text-ink-muted">Sin notas.</li>}
        {notes.map((n) => (
          <li key={n.id} className="rounded-lg bg-warning-50 p-2 text-sm text-ink">
            <p className="whitespace-pre-wrap break-words">{n.body}</p>
            <p className="mt-1 text-[11px] text-ink-muted">{n.author ?? 'Sistema'} · {formatDateTime(n.created_at)}</p>
          </li>
        ))}
      </ul>
      {conversation.can_reply && (
        <div className="mt-3 space-y-2">
          <label htmlFor="note-text" className="sr-only">Nueva nota interna</label>
          <textarea id="note-text" value={text} onChange={(e) => setText(e.target.value)} rows={2} maxLength={2000} placeholder="Agregar una nota interna"
            className="w-full rounded-lg border border-line-strong bg-surface px-3 py-2 text-sm text-ink focus:border-jge-blue focus:outline-none focus:ring-2 focus:ring-jge-blue/20" />
          <Button small variant="secondary" onClick={() => void add()} loading={saving} disabled={!text.trim()}>Guardar nota</Button>
        </div>
      )}
    </aside>
  )
}
