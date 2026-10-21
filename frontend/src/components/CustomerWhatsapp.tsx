import { useState } from 'react'
import { api } from '../lib/api'
import { formatDateTime } from '../lib/format'
import { useLoad } from '../lib/useLoad'
import { useToast } from './toast'
import { Alert, Badge, Button, Card, FormError, SelectField, TextField } from './ui'

interface Template { id: number; name: string; body: string; variables: number }
interface WaMessage { id: number; body: string; status: 'sent' | 'delivered' | 'read' | 'failed' | 'not_sent'; error: string | null; created_at: string }
interface WaState {
  number: string | null
  consent: 'granted' | 'revoked' | null
  blockers: string[]
  ready: boolean
  consents: { status: string; source: string; created_at: string }[]
  templates: Template[]
  messages: WaMessage[]
}

const STATUS = { sent: 'Enviado', delivered: 'Entregado', read: 'Leído', failed: 'Falló', not_sent: 'No enviado' } as const
const CONSENT = { granted: 'Aceptó recibir mensajes', revoked: 'Retiró su consentimiento', none: 'Sin consentimiento registrado' } as const

function Panel({ customerId, state, reload }: { customerId: number; state: WaState; reload: () => void }) {
  const toast = useToast()
  const [source, setSource] = useState('')
  const [templateId, setTemplateId] = useState('')
  const [params, setParams] = useState<string[]>([])
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const template = state.templates.find((t) => String(t.id) === templateId)

  async function run(action: () => Promise<void>) {
    setBusy(true)
    setError(null)
    try { await action() } catch (e) { setError(e instanceof Error ? e.message : 'No se pudo completar.') } finally { setBusy(false) }
  }
  const consent = (status: 'granted' | 'revoked') => run(async () => {
    await api(`/customers/${customerId}/whatsapp/consent`, { method: 'POST', body: { status, source } })
    setSource('')
    reload()
  })
  const send = () => run(async () => {
    const r = await api<{ data: WaMessage }>(`/customers/${customerId}/whatsapp/send`, { method: 'POST', body: { template_id: Number(templateId), params: params.slice(0, template?.variables ?? 0) } })
    if (r.data.status === 'not_sent' || r.data.status === 'failed') setError(r.data.error)
    else toast.success('Mensaje enviado.')
    reload()
  })

  return (
    <Card className="p-4 text-sm">
      <p>
        <span className="font-medium">{CONSENT[state.consent ?? 'none']}</span>
        {state.number ? <span className="text-ink-muted"> · {state.number}</span> : <span className="text-ink-muted"> · sin número de WhatsApp</span>}
      </p>
      {state.blockers.length > 0 && <div className="mt-2"><Alert tone="warning">No se puede enviar: {state.blockers.join(' ')}</Alert></div>}
      <div className="mt-3 flex flex-wrap items-end gap-2">
        <TextField id="wa-source" label="Cómo se obtuvo o por qué se retira" value={source} onChange={(e) => setSource(e.target.value)} />
        <Button variant="secondary" loading={busy} disabled={source.trim().length < 3} onClick={() => void consent('granted')}>Registrar consentimiento</Button>
        <Button variant="secondary" loading={busy} disabled={source.trim().length < 3} onClick={() => void consent('revoked')}>Registrar retiro</Button>
      </div>
      {state.templates.length > 0 && (
        <div className="mt-4 flex flex-wrap items-end gap-2 border-t border-line pt-3">
          <SelectField id="wa-template" label="Plantilla aprobada" value={templateId} onChange={(e) => { setTemplateId(e.target.value); setParams([]) }}>
            <option value="">Elegir…</option>
            {state.templates.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
          </SelectField>
          {Array.from({ length: template?.variables ?? 0 }, (_, i) => (
            <TextField key={i} id={`wa-param-${i + 1}`} label={`Valor ${i + 1}`} value={params[i] ?? ''} onChange={(e) => setParams((p) => { const n = [...p]; n[i] = e.target.value; return n })} />
          ))}
          <Button loading={busy} disabled={!template || state.blockers.length > 0 || !state.ready} onClick={() => void send()}>Enviar mensaje</Button>
          {template && <p className="w-full text-xs text-ink-muted">{template.body}</p>}
        </div>
      )}
      <FormError message={error} />
      {state.messages.length > 0 && (
        <ul className="mt-4 space-y-2 border-t border-line pt-3" aria-label="Mensajes de WhatsApp">
          {state.messages.map((m) => (
            <li key={m.id}><Badge>{STATUS[m.status]}</Badge> <span className="text-ink-muted">{formatDateTime(m.created_at)}</span> {m.body}{m.error && <span className="block text-xs text-danger">{m.error}</span>}</li>
          ))}
        </ul>
      )}
      <p className="mt-3 text-xs text-ink-muted">Solo se envían plantillas aprobadas, con consentimiento vigente y nunca a clientes «no contactar». Los contactos hechos por tu cuenta se siguen registrando en Seguimiento.</p>
    </Card>
  )
}

/** WhatsApp del cliente en su ficha: consentimiento, envío de plantillas aprobadas e historial. */
export default function CustomerWhatsapp({ customerId }: { customerId: number }) {
  const result = useLoad<{ data: WaState }>(`/customers/${customerId}/whatsapp`)
  if (!result.data) return null

  return (
    <section aria-labelledby="wa-title" className="mb-6">
      <h2 id="wa-title" className="mb-2 text-sm font-semibold text-ink">WhatsApp</h2>
      <Panel customerId={customerId} state={result.data.data} reload={result.reload} />
    </section>
  )
}
