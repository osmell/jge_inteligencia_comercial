import { AlertTriangle, Check, CheckCheck, Clock, FileText, Reply, RotateCcw } from 'lucide-react'
import { formatBytes } from '../../lib/format'
import { attachmentUrl, STATUS_TEXT, type ChatMessage } from '../../lib/conversations'

function Ticks({ status }: { status: string }) {
  const text = STATUS_TEXT[status] ?? status
  const cls = 'inline h-3.5 w-3.5'
  return (
    <span className="inline-flex items-center" title={text}>
      <span className="sr-only">{text}</span>
      {status === 'queued' && <Clock className={cls} aria-hidden="true" />}
      {status === 'sent' && <Check className={cls} aria-hidden="true" />}
      {status === 'delivered' && <CheckCheck className={cls} aria-hidden="true" />}
      {status === 'read' && <CheckCheck className={`${cls} text-jge-blue`} aria-hidden="true" />}
      {(status === 'failed' || status === 'not_sent') && <AlertTriangle className={`${cls} text-danger`} aria-hidden="true" />}
    </span>
  )
}

function Attachment({ a }: { a: ChatMessage['attachments'][number] }) {
  if (a.status === 'pending') return <p className="text-xs italic text-ink-muted">Descargando archivo…</p>
  if (a.status === 'failed') return <p className="text-xs text-danger">No se pudo guardar el archivo{a.error ? `: ${a.error}` : '.'}</p>
  const src = attachmentUrl(a.id)
  if (a.kind === 'image') {
    return (
      <a href={src} target="_blank" rel="noreferrer" aria-label={`Abrir imagen ${a.name ?? ''}`.trim()}>
        <img src={src} alt={a.name ?? 'Imagen enviada en la conversación'} loading="lazy" className="max-h-64 w-full rounded-lg object-cover" />
      </a>
    )
  }
  if (a.kind === 'video') return <video src={src} controls preload="metadata" className="max-h-64 w-full rounded-lg" aria-label={a.name ?? 'Video'} />
  if (a.kind === 'audio') return <audio src={src} controls preload="none" className="w-full max-w-64" aria-label={a.name ?? 'Audio'} />
  return (
    <a href={attachmentUrl(a.id, false)} className="flex items-center gap-2 rounded-lg bg-black/5 px-3 py-2 text-sm text-ink hover:bg-black/10">
      <FileText className="h-5 w-5 shrink-0 text-jge-blue" aria-hidden="true" />
      <span className="min-w-0 flex-1 truncate">{a.name ?? 'Documento'}</span>
      {a.size !== null && <span className="shrink-0 text-xs text-ink-muted">{formatBytes(a.size)}</span>}
    </a>
  )
}

/** Burbuja de un mensaje: a la derecha lo que escribimos, a la izquierda lo del cliente. */
export default function MessageBubble({ m, canReply, onReply, onRetry }: { m: ChatMessage; canReply: boolean; onReply: (m: ChatMessage) => void; onRetry: (m: ChatMessage) => void }) {
  const mine = m.direction === 'out'
  const time = new Date(m.created_at).toLocaleTimeString('es', { hour: '2-digit', minute: '2-digit' })
  const failed = m.status === 'failed'
  if (m.type === 'reaction') {
    return <p className={`my-0.5 text-xs text-ink-muted ${mine ? 'text-right' : ''}`}>{mine ? 'Reaccionaste' : 'El cliente reaccionó'} {m.body}</p>
  }
  return (
    <div className={`group flex ${mine ? 'justify-end' : 'justify-start'}`}>
      <div className={`relative max-w-[85%] rounded-xl px-3 py-2 text-sm shadow-sm sm:max-w-[70%] ${mine ? 'rounded-br-sm bg-jge-blue-50' : 'rounded-bl-sm border border-line bg-surface'}`}>
        {mine && m.author && <p className="mb-0.5 text-[11px] font-semibold text-jge-blue">{m.author}</p>}
        {m.type === 'template' && <p className="mb-0.5 text-[11px] font-medium uppercase tracking-wide text-ink-muted">Plantilla</p>}
        {m.reply_to && (
          <p className="mb-1.5 line-clamp-2 rounded border-l-4 border-jge-blue bg-black/5 px-2 py-1 text-xs text-ink-muted">
            <span className="font-semibold">{m.reply_to.direction === 'out' ? 'Tú' : 'Cliente'}: </span>
            {m.reply_to.body || 'Archivo'}
          </p>
        )}
        {m.attachments.length > 0 && <div className="mb-1 space-y-1.5">{m.attachments.map((a) => <Attachment key={a.id} a={a} />)}</div>}
        {m.body && <p className="whitespace-pre-wrap break-words text-ink">{m.body}</p>}
        <p className="mt-1 flex items-center justify-end gap-1.5 text-[11px] text-ink-subtle">
          <span>{time}</span>
          {mine && <Ticks status={m.status} />}
        </p>
        {failed && (
          <p className="mt-1 text-xs text-danger" role="alert">
            {m.error ?? 'No se pudo enviar.'}
            {canReply && (
              <button type="button" onClick={() => onRetry(m)} className="ml-2 inline-flex cursor-pointer items-center gap-1 font-medium underline">
                <RotateCcw className="h-3 w-3" aria-hidden="true" /> Reintentar
              </button>
            )}
          </p>
        )}
        {canReply && (
          <button
            type="button"
            onClick={() => onReply(m)}
            aria-label="Responder a este mensaje"
            className={`absolute top-1 hidden h-7 w-7 cursor-pointer items-center justify-center rounded-full border border-line bg-surface text-ink-muted shadow-sm hover:text-ink focus:flex group-hover:flex ${mine ? '-left-8' : '-right-8'}`}
          >
            <Reply className="h-3.5 w-3.5" aria-hidden="true" />
          </button>
        )}
      </div>
    </div>
  )
}
