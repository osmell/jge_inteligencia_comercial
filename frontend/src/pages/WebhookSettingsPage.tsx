import { useState, type FormEvent } from 'react'
import { useToast } from '../components/toast'
import { Alert, Badge, Button, Card, CheckField, DataTable, EmptyRow, FormError, PageHeader, TextField, td, th, tr } from '../components/ui'
import { api } from '../lib/api'
import { formatDateTime } from '../lib/format'
import { useLoad } from '../lib/useLoad'

interface HookConfig { enabled: boolean; url: string | null; has_secret: boolean; events: string[]; available_events: { type: string; label: string }[]; ready: boolean }
interface Delivery { id: number; event: string; status: 'pending' | 'sending' | 'sent' | 'failed'; attempt: number; http_status: number | null; error: string | null; created_at: string }

const STATUS = { pending: 'Pendiente', sending: 'Enviando', sent: 'Enviado', failed: 'Falló' } as const

function ConfigForm({ config, onSaved }: { config: HookConfig; onSaved: () => void }) {
  const toast = useToast()
  const [form, setForm] = useState({ enabled: config.enabled, url: config.url ?? '', secret: '', events: config.events })
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const toggle = (type: string, on: boolean) => setForm((f) => ({ ...f, events: on ? [...f.events, type] : f.events.filter((t) => t !== type) }))

  async function save(e: FormEvent) {
    e.preventDefault()
    setBusy(true)
    setError(null)
    try {
      await api('/webhook/settings', { method: 'PUT', body: form })
      toast.success('Webhook guardado.')
      onSaved()
    } catch (err) { setError(err instanceof Error ? err.message : 'No se pudo guardar.') } finally { setBusy(false) }
  }
  async function test() {
    setBusy(true)
    setError(null)
    try {
      const r = await api<{ data: Delivery }>('/webhook/test', { method: 'POST' })
      if (r.data.status === 'sent') toast.success('Envío de prueba correcto.')
      else setError(r.data.error ?? 'El destino no aceptó la prueba.')
      onSaved()
    } catch (err) { setError(err instanceof Error ? err.message : 'No se pudo probar.') } finally { setBusy(false) }
  }

  return (
    <Card className="p-4">
      <form onSubmit={(e) => void save(e)} className="space-y-3">
        <CheckField label="Enviar eventos al webhook" checked={form.enabled} onChange={(e) => setForm({ ...form, enabled: e.target.checked })} />
        <TextField id="wh-url" label="Dirección de destino (URL)" value={form.url} onChange={(e) => setForm({ ...form, url: e.target.value })} />
        <TextField id="wh-secret" label="Secreto de firma" type="password" autoComplete="new-password" value={form.secret} onChange={(e) => setForm({ ...form, secret: e.target.value })} hint={config.has_secret ? 'Hay un secreto guardado (cifrado). Déjalo vacío para conservarlo.' : 'Mínimo 16 caracteres. Se guarda cifrado y no se vuelve a mostrar.'} />
        <fieldset className="space-y-1">
          <legend className="mb-1 text-sm font-medium text-ink">Eventos que se envían</legend>
          {config.available_events.map((ev) => <CheckField key={ev.type} label={ev.label} checked={form.events.includes(ev.type)} onChange={(e) => toggle(ev.type, e.target.checked)} />)}
        </fieldset>
        <p className="text-xs text-ink-muted">Cada envío es un POST con JSON y los encabezados <code>X-JGE-Event</code>, <code>X-JGE-Delivery</code>, <code>X-JGE-Timestamp</code> y <code>X-JGE-Signature</code> (<code>sha256=</code> + HMAC-SHA256 de «marca de tiempo.cuerpo» con el secreto). El webhook solo avisa; no recibe órdenes ni cambia datos.</p>
        <FormError message={error} />
        <div className="flex gap-2">
          <Button type="submit" loading={busy}>Guardar</Button>
          <Button variant="secondary" loading={busy} onClick={() => void test()}>Enviar prueba</Button>
        </div>
      </form>
    </Card>
  )
}

/** Webhook de salida genérico (n8n u otro receptor) y su registro de envíos. El secreto nunca se devuelve del servidor. */
export default function WebhookSettingsPage() {
  const config = useLoad<{ data: HookConfig }>('/webhook/settings')
  const log = useLoad<{ data: Delivery[] }>('/webhook/log')
  const reload = () => { config.reload(); log.reload() }

  return (
    <section>
      <PageHeader title="Webhook" description="Avisa a otro sistema (por ejemplo n8n) cuando ocurre un evento del sistema. Los mensajes de WhatsApp y los correos se envían desde aquí mismo, sin depender de él." />
      <FormError message={config.error ?? log.error} />
      {config.data && !config.data.data.ready && <div className="mb-3"><Alert tone="warning">El webhook no está activo: no se envía ningún evento.</Alert></div>}
      {config.data && <ConfigForm key={`${config.data.data.url}${config.data.data.enabled}${config.data.data.has_secret}${config.data.data.events.join()}`} config={config.data.data} onSaved={reload} />}
      <h2 className="mb-2 mt-6 text-sm font-semibold text-ink">Registro de envíos</h2>
      <DataTable label="Registro de envíos del webhook">
        <thead><tr><th className={th}>Fecha</th><th className={th}>Evento</th><th className={th}>Estado</th><th className={th}>Detalle</th></tr></thead>
        <tbody>
          {(log.data?.data ?? []).length === 0 ? <EmptyRow cols={4}>Todavía no se ha enviado ningún evento.</EmptyRow> : (log.data?.data ?? []).map((d) => (
            <tr key={d.id} className={tr}><td className={td}>{formatDateTime(d.created_at)}</td><td className={td}>{d.event}</td><td className={td}><Badge>{STATUS[d.status]}</Badge></td><td className={td}>{d.error ?? (d.attempt > 1 ? `${d.attempt} intentos` : '')}</td></tr>
          ))}
        </tbody>
      </DataTable>
    </section>
  )
}
