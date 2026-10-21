import { FileText, Zap } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useAuth } from '../../auth/AuthContext'
import { api, ApiError } from '../../lib/api'
import { formatBytes, formatMoney } from '../../lib/format'
import type { ChatMessage, ConversationRow, QuickReply, QuotationFile } from '../../lib/conversations'
import { fillQuickReply } from '../../lib/conversations'
import { Alert, Button, Modal } from '../ui'

const fail = (e: unknown) => (e instanceof ApiError ? (Object.values(e.errors)[0]?.[0] ?? e.message) : e instanceof Error ? e.message : 'No se pudo completar la acción.')
const iconButton = 'flex h-11 w-11 shrink-0 cursor-pointer items-center justify-center rounded-lg border border-line-strong text-ink-muted hover:bg-surface-muted'

/** Respuestas rápidas: personales o compartidas por supervisión; «{nombre}» se reemplaza por el nombre del cliente. */
export function QuickReplies({ customerName, onPick }: { customerName: string | null; onPick: (text: string) => void }) {
  const { user } = useAuth()
  const [open, setOpen] = useState(false)
  const [items, setItems] = useState<QuickReply[]>([])
  const [error, setError] = useState<string | null>(null)
  const [title, setTitle] = useState('')
  const [body, setBody] = useState('')
  const [shared, setShared] = useState(false)
  const [saving, setSaving] = useState(false)
  const supervisor = user?.role.slug === 'supervisor_ventas'

  const load = async () => {
    try {
      setItems((await api<{ data: QuickReply[] }>('/conversations/quick-replies')).data)
      setError(null)
    } catch (e) {
      setError(fail(e))
    }
  }
  useEffect(() => {
    if (!open) return
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void load()
  }, [open])

  async function create() {
    setSaving(true)
    setError(null)
    try {
      await api('/conversations/quick-replies', { method: 'POST', body: { title, body, shared } })
      setTitle('')
      setBody('')
      setShared(false)
      await load()
    } catch (e) {
      setError(fail(e))
    } finally {
      setSaving(false)
    }
  }

  async function remove(id: number) {
    try {
      await api(`/conversations/quick-replies/${id}`, { method: 'PUT', body: { is_active: false } })
      await load()
    } catch (e) {
      setError(fail(e))
    }
  }

  return (
    <>
      <button type="button" onClick={() => setOpen(true)} aria-label="Respuestas rápidas" className={iconButton}><Zap className="h-5 w-5" aria-hidden="true" /></button>
      {open && (
        <Modal title="Respuestas rápidas" onClose={() => setOpen(false)}>
          <div className="space-y-4">
            {error && <Alert tone="danger">{error}</Alert>}
            <ul className="max-h-56 divide-y divide-line overflow-y-auto rounded-lg border border-line" aria-label="Respuestas disponibles">
              {items.length === 0 && <li className="p-3 text-sm text-ink-muted">Todavía no hay respuestas rápidas.</li>}
              {items.map((r) => (
                <li key={r.id} className="flex items-start gap-2 p-3">
                  <button type="button" onClick={() => { onPick(fillQuickReply(r.body, customerName)); setOpen(false) }} className="min-w-0 flex-1 cursor-pointer text-left">
                    <span className="block text-sm font-medium text-ink">{r.title}{r.shared && <span className="ml-2 rounded bg-jge-blue-50 px-1.5 text-[11px] text-jge-blue">Compartida</span>}</span>
                    <span className="line-clamp-2 text-xs text-ink-muted">{r.body}</span>
                  </button>
                  {r.mine && <button type="button" onClick={() => void remove(r.id)} aria-label={`Quitar la respuesta ${r.title}`} className="cursor-pointer text-xs text-danger hover:underline">Quitar</button>}
                </li>
              ))}
            </ul>
            <div className="space-y-2">
              <h3 className="text-sm font-semibold text-ink">Nueva respuesta</h3>
              <label htmlFor="qr-title" className="block text-xs text-ink-muted">Título</label>
              <input id="qr-title" value={title} onChange={(e) => setTitle(e.target.value)} maxLength={60} className="min-h-10 w-full rounded-lg border border-line-strong bg-surface px-3 text-sm" />
              <label htmlFor="qr-body" className="block text-xs text-ink-muted">Texto (usa {'{nombre}'} para el nombre del cliente)</label>
              <textarea id="qr-body" value={body} onChange={(e) => setBody(e.target.value)} maxLength={1000} rows={3} className="w-full rounded-lg border border-line-strong bg-surface px-3 py-2 text-sm" />
              {supervisor && <label className="flex items-center gap-2 text-sm text-ink"><input type="checkbox" checked={shared} onChange={(e) => setShared(e.target.checked)} /> Compartir con todo el equipo</label>}
              <Button small onClick={() => void create()} loading={saving} disabled={title.trim().length < 2 || !body.trim()}>Guardar respuesta</Button>
            </div>
          </div>
        </Modal>
      )}
    </>
  )
}

/** Envía por la conversación el archivo adjunto de una cotización del cliente. */
export function QuotationSender({ conversation, onSent }: { conversation: ConversationRow; onSent: (m: ChatMessage) => void }) {
  const [open, setOpen] = useState(false)
  const [items, setItems] = useState<QuotationFile[] | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [sending, setSending] = useState<number | null>(null)

  useEffect(() => {
    if (!open) return
    let alive = true
    api<{ data: QuotationFile[] }>(`/conversations/${conversation.id}/quotations`).then((r) => alive && setItems(r.data)).catch((e: unknown) => alive && setError(fail(e)))
    return () => { alive = false }
  }, [open, conversation.id])

  async function send(q: QuotationFile) {
    setSending(q.id)
    setError(null)
    try {
      const r = await api<{ data: ChatMessage }>(`/conversations/${conversation.id}/quotation`, { method: 'POST', body: { quotation_id: q.id, client_key: crypto.randomUUID() } })
      onSent(r.data)
      setOpen(false)
    } catch (e) {
      setError(fail(e))
    } finally {
      setSending(null)
    }
  }

  return (
    <>
      <button type="button" onClick={() => setOpen(true)} aria-label="Enviar una cotización" className={iconButton}><FileText className="h-5 w-5" aria-hidden="true" /></button>
      {open && (
        <Modal title="Enviar una cotización" onClose={() => setOpen(false)}>
          <div className="space-y-3">
            {error && <Alert tone="danger">{error}</Alert>}
            {items === null && !error && <p className="text-sm text-ink-muted">Cargando cotizaciones…</p>}
            {items?.length === 0 && <p className="text-sm text-ink-muted">Este cliente no tiene cotizaciones con archivo adjunto. Adjunta el PDF en la cotización para poder enviarlo desde aquí.</p>}
            <ul className="divide-y divide-line rounded-lg border border-line" aria-label="Cotizaciones con archivo">
              {(items ?? []).map((q) => (
                <li key={q.id} className="flex items-center gap-3 p-3">
                  <span className="min-w-0 flex-1 text-sm">
                    <span className="block font-medium text-ink">{q.number} · {formatMoney(q.amount)}</span>
                    <span className="block truncate text-xs text-ink-muted">{q.name}{q.size ? ` · ${formatBytes(q.size)}` : ''}</span>
                  </span>
                  <Button small onClick={() => void send(q)} loading={sending === q.id} aria-label={`Enviar ${q.number}`}>Enviar</Button>
                </li>
              ))}
            </ul>
          </div>
        </Modal>
      )}
    </>
  )
}
