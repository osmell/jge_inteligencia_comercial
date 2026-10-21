import { Lock, Paperclip, Send, X } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { api, ApiError } from '../../lib/api'
import { formatBytes } from '../../lib/format'
import { renderTemplate, type ChatMessage, type ConversationRow, type Template } from '../../lib/conversations'
import { Alert, Button } from '../ui'
import { QuickReplies, QuotationSender } from './ComposerTools'

/** Tamaños máximos de WhatsApp por clase de archivo (el servidor los vuelve a comprobar). */
const LIMITS: [RegExp, number, string][] = [[/^image\//, 5, 'imagen'], [/^video\//, 16, 'video'], [/^audio\//, 16, 'audio']]
const ACCEPT = 'image/jpeg,image/png,video/mp4,video/3gpp,audio/aac,audio/mp4,audio/mpeg,audio/amr,audio/ogg,application/pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt'

function limitError(file: File): string | null {
  const [, mb, label] = LIMITS.find(([re]) => re.test(file.type)) ?? [null, 100, 'documento']
  return file.size > (mb as number) * 1024 * 1024 ? `El archivo supera el máximo de WhatsApp para ${label as string} (${mb} MB).` : null
}

function failure(e: unknown): string {
  if (e instanceof ApiError) return Object.values(e.errors)[0]?.[0] ?? e.message
  return e instanceof Error ? e.message : 'No se pudo enviar el mensaje.'
}

/** Plantilla aprobada: lo único que se puede enviar cuando pasaron más de 24 horas desde el último mensaje del cliente. */
function TemplateSender({ conversation, onSent }: { conversation: ConversationRow; onSent: (m: ChatMessage) => void }) {
  const [templates, setTemplates] = useState<Template[] | null>(null)
  const [templateId, setTemplateId] = useState('')
  const [params, setParams] = useState<string[]>([])
  const [sending, setSending] = useState(false)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    let alive = true
    api<{ data: Template[] }>('/conversations/templates').then((r) => alive && setTemplates(r.data)).catch((e: unknown) => alive && setError(failure(e)))
    return () => { alive = false }
  }, [])

  const template = templates?.find((t) => String(t.id) === templateId)

  async function send() {
    if (!template) return
    setSending(true)
    setError(null)
    try {
      const r = await api<{ data: ChatMessage }>(`/conversations/${conversation.id}/template`, { method: 'POST', body: { template_id: template.id, params: params.slice(0, template.variables) } })
      onSent(r.data)
      setTemplateId('')
      setParams([])
    } catch (e) {
      setError(failure(e))
    } finally {
      setSending(false)
    }
  }

  return (
    <div className="space-y-2 border-t border-line bg-warning-50 p-3">
      <p className="flex items-start gap-2 text-sm text-ink"><Lock className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" /> Pasaron más de 24 horas desde el último mensaje del cliente. WhatsApp solo permite enviar una plantilla aprobada hasta que el cliente responda.</p>
      {error && <Alert tone="danger">{error}</Alert>}
      {templates !== null && templates.length === 0 && <p className="text-sm text-ink-muted">No hay plantillas aprobadas. Pídele a administración que las registre.</p>}
      {templates !== null && templates.length > 0 && (
        <div className="space-y-2">
          <label htmlFor="tpl" className="block text-sm font-medium text-ink">Plantilla</label>
          <select id="tpl" value={templateId} onChange={(e) => { setTemplateId(e.target.value); setParams([]) }} className="min-h-10 w-full rounded-lg border border-line-strong bg-surface px-3 text-sm">
            <option value="">Elige una plantilla…</option>
            {templates.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
          </select>
          {template && Array.from({ length: template.variables }, (_, i) => (
            <div key={i}>
              <label htmlFor={`tpl-p${i}`} className="block text-xs text-ink-muted">Valor para {`{{${i + 1}}}`}</label>
              <input id={`tpl-p${i}`} value={params[i] ?? ''} onChange={(e) => setParams((p) => { const n = [...p]; n[i] = e.target.value; return n })} maxLength={300} className="min-h-10 w-full rounded-lg border border-line-strong bg-surface px-3 text-sm" />
            </div>
          ))}
          {template && <p className="whitespace-pre-wrap rounded-lg bg-surface p-2 text-sm text-ink" aria-label="Vista previa de la plantilla">{renderTemplate(template.body, params)}</p>}
          <Button small onClick={() => void send()} loading={sending} disabled={!template || params.slice(0, template.variables).filter(Boolean).length < template.variables} icon={<Send className="h-4 w-4" aria-hidden="true" />}>Enviar plantilla</Button>
        </div>
      )}
    </div>
  )
}

export default function Composer({
  conversation, windowOpen, replyTo, onClearReply, onSent,
}: {
  conversation: ConversationRow
  windowOpen: boolean
  replyTo: ChatMessage | null
  onClearReply: () => void
  onSent: (m: ChatMessage) => void
}) {
  const [text, setText] = useState('')
  const [file, setFile] = useState<File | null>(null)
  const [sending, setSending] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const input = useRef<HTMLInputElement>(null)
  const area = useRef<HTMLTextAreaElement>(null)
  // La misma clave en cada reintento del mismo envío: si la petición se repite, el servidor no manda el mensaje dos veces.
  const key = useRef<string>(crypto.randomUUID())

  useEffect(() => { if (replyTo) area.current?.focus() }, [replyTo])

  if (!conversation.can_reply) {
    return (
      <p className="flex items-center gap-2 border-t border-line bg-surface-muted p-3 text-sm text-ink-muted">
        <Lock className="h-4 w-4 shrink-0" aria-hidden="true" />
        {conversation.customer ? 'Estás viendo esta conversación en modo solo lectura.' : 'Este número no está asignado a un cliente: supervisión debe asignarlo antes de contestar.'}
      </p>
    )
  }
  if (!windowOpen) return <TemplateSender conversation={conversation} onSent={onSent} />

  function pick(f: File | null) {
    setError(f ? limitError(f) : null)
    setFile(f && !limitError(f) ? f : null)
    if (input.current) input.current.value = ''
  }

  async function send() {
    if (sending || (!text.trim() && !file)) return
    setSending(true)
    setError(null)
    const form = new FormData()
    if (text.trim()) form.set('body', text.trim())
    if (file) form.set('file', file)
    if (replyTo) form.set('reply_to_id', String(replyTo.id))
    form.set('client_key', key.current)
    try {
      const r = await api<{ data: ChatMessage }>(`/conversations/${conversation.id}/messages`, { method: 'POST', body: form })
      onSent(r.data)
      setText('')
      setFile(null)
      onClearReply()
      key.current = crypto.randomUUID()
    } catch (e) {
      setError(failure(e))
    } finally {
      setSending(false)
    }
  }

  return (
    <div className="border-t border-line bg-surface p-3">
      {replyTo && (
        <div className="mb-2 flex items-start gap-2 rounded-lg border-l-4 border-jge-blue bg-surface-muted px-3 py-1.5 text-xs text-ink-muted">
          <p className="line-clamp-2 flex-1"><span className="font-semibold">Respondiendo a {replyTo.direction === 'out' ? 'tu mensaje' : 'el cliente'}: </span>{replyTo.body || 'Archivo'}</p>
          <button type="button" onClick={onClearReply} aria-label="Cancelar la respuesta citada" className="cursor-pointer text-ink-muted hover:text-ink"><X className="h-4 w-4" aria-hidden="true" /></button>
        </div>
      )}
      {file && (
        <div className="mb-2 flex items-center gap-2 rounded-lg bg-surface-muted px-3 py-1.5 text-sm text-ink">
          <Paperclip className="h-4 w-4 shrink-0" aria-hidden="true" />
          <span className="min-w-0 flex-1 truncate">{file.name}</span>
          <span className="shrink-0 text-xs text-ink-muted">{formatBytes(file.size)}</span>
          <button type="button" onClick={() => setFile(null)} aria-label="Quitar el archivo" className="cursor-pointer text-ink-muted hover:text-ink"><X className="h-4 w-4" aria-hidden="true" /></button>
        </div>
      )}
      {error && <div className="mb-2"><Alert tone="danger">{error}</Alert></div>}
      <div className="flex items-end gap-2">
        <input ref={input} type="file" accept={ACCEPT} className="sr-only" aria-label="Archivo adjunto" tabIndex={-1} onChange={(e) => pick(e.target.files?.[0] ?? null)} />
        <button type="button" onClick={() => input.current?.click()} aria-label="Adjuntar archivo" className="flex h-11 w-11 shrink-0 cursor-pointer items-center justify-center rounded-lg border border-line-strong text-ink-muted hover:bg-surface-muted"><Paperclip className="h-5 w-5" aria-hidden="true" /></button>
        <QuickReplies customerName={conversation.customer?.name ?? null} onPick={(t) => { setText((cur) => (cur ? `${cur} ${t}` : t)); area.current?.focus() }} />
        <QuotationSender conversation={conversation} onSent={onSent} />
        <div className="min-w-0 flex-1">
          <label htmlFor="chat-text" className="sr-only">Mensaje</label>
          <textarea id="chat-text" ref={area} value={text} rows={1} maxLength={4096} placeholder="Escribe un mensaje"
            onChange={(e) => setText(e.target.value)}
            onKeyDown={(e) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); void send() } }}
            className="max-h-32 min-h-11 w-full resize-none rounded-lg border border-line-strong bg-surface px-3 py-2.5 text-sm text-ink focus:border-jge-blue focus:outline-none focus:ring-2 focus:ring-jge-blue/20" />
        </div>
        <Button onClick={() => void send()} loading={sending} disabled={!text.trim() && !file} aria-label="Enviar mensaje" icon={<Send className="h-4 w-4" aria-hidden="true" />}><span className="hidden sm:inline">Enviar</span></Button>
      </div>
      <p className="mt-1 text-[11px] text-ink-subtle">Enter envía, Shift+Enter agrega una línea.</p>
    </div>
  )
}
