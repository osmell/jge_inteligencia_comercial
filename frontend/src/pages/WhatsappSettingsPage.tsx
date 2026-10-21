import { useState, type FormEvent } from 'react'
import { useToast } from '../components/toast'
import { Alert, Badge, Button, Card, CheckField, DataTable, EmptyRow, FormError, PageHeader, SelectField, TextField, td, th, tr } from '../components/ui'
import { api } from '../lib/api'
import { formatDateTime } from '../lib/format'
import { useLoad } from '../lib/useLoad'

interface WaConfig { enabled: boolean; phone_number_id: string | null; business_account_id: string | null; api_version: string; has_access_token: boolean; has_app_secret: boolean; has_verify_token: boolean; ready: boolean }
interface Template { id: number; name: string; language: string; category: string; body: string; status: 'draft' | 'pending' | 'approved' | 'rejected'; variables: number }
interface Message { id: number; customer: string | null; to: string | null; body: string; status: 'sent' | 'delivered' | 'read' | 'failed' | 'not_sent'; error: string | null; created_at: string }

const STATUS = { sent: 'Enviado', delivered: 'Entregado', read: 'Leído', failed: 'Falló', not_sent: 'No enviado' } as const
const TEMPLATE_STATUS = { draft: 'Borrador', pending: 'En revisión', approved: 'Aprobada', rejected: 'Rechazada' } as const

function ConfigForm({ config, onSaved }: { config: WaConfig; onSaved: () => void }) {
  const toast = useToast()
  const [form, setForm] = useState({ enabled: config.enabled, phone_number_id: config.phone_number_id ?? '', business_account_id: config.business_account_id ?? '', api_version: config.api_version, access_token: '', app_secret: '', verify_token: '' })
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [result, setResult] = useState<{ ok: boolean; message: string } | null>(null)
  const set = (k: keyof typeof form, v: string | boolean) => setForm((f) => ({ ...f, [k]: v }))
  const kept = (has: boolean) => (has ? 'Hay un valor guardado (cifrado). Déjalo vacío para conservarlo.' : 'Se guarda cifrado y no se vuelve a mostrar.')

  async function save(e: FormEvent) {
    e.preventDefault()
    setBusy(true)
    setError(null)
    try {
      await api('/whatsapp/settings', { method: 'PUT', body: form })
      toast.success('Configuración de WhatsApp guardada.')
      onSaved()
    } catch (err) { setError(err instanceof Error ? err.message : 'No se pudo guardar.') } finally { setBusy(false) }
  }
  async function test() {
    setBusy(true)
    setError(null)
    try { setResult((await api<{ data: { ok: boolean; message: string } }>('/whatsapp/test', { method: 'POST' })).data) } catch (err) { setError(err instanceof Error ? err.message : 'No se pudo probar.') } finally { setBusy(false) }
  }

  return (
    <Card className="p-4">
      <form onSubmit={(e) => void save(e)} className="grid gap-3 sm:grid-cols-2">
        <div className="sm:col-span-2"><CheckField label="Enviar mensajes por WhatsApp desde el sistema" checked={form.enabled} onChange={(e) => set('enabled', e.target.checked)} /></div>
        <TextField id="wa-phone-id" label="Identificador del número (Phone number ID)" value={form.phone_number_id} onChange={(e) => set('phone_number_id', e.target.value)} />
        <TextField id="wa-waba" label="Identificador de la cuenta de WhatsApp Business" value={form.business_account_id} onChange={(e) => set('business_account_id', e.target.value)} />
        <TextField id="wa-version" label="Versión de la API" value={form.api_version} onChange={(e) => set('api_version', e.target.value)} />
        <TextField id="wa-token" label="Token de acceso" type="password" autoComplete="new-password" value={form.access_token} onChange={(e) => set('access_token', e.target.value)} hint={kept(config.has_access_token)} />
        <TextField id="wa-secret" label="Secreto de la aplicación (firma del webhook)" type="password" autoComplete="new-password" value={form.app_secret} onChange={(e) => set('app_secret', e.target.value)} hint={kept(config.has_app_secret)} />
        <TextField id="wa-verify" label="Token de verificación del webhook" type="password" autoComplete="new-password" value={form.verify_token} onChange={(e) => set('verify_token', e.target.value)} hint={kept(config.has_verify_token)} />
        <p className="text-xs text-ink-muted sm:col-span-2">Dirección del webhook para configurar en Meta: <code>/api/v1/webhooks/whatsapp</code> (recibe entregado, leído y fallido).</p>
        <div className="sm:col-span-2"><FormError message={error} /></div>
        <div className="flex gap-2 sm:col-span-2">
          <Button type="submit" loading={busy}>Guardar</Button>
          <Button variant="secondary" loading={busy} onClick={() => void test()}>Probar conexión</Button>
        </div>
      </form>
      {result && <div className="mt-3"><Alert tone={result.ok ? 'success' : 'danger'}>{result.message}</Alert></div>}
    </Card>
  )
}

function Templates({ rows, onSaved }: { rows: Template[]; onSaved: () => void }) {
  const [form, setForm] = useState({ name: '', language: 'es', category: 'utility', body: '', status: 'draft' })
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  async function add(e: FormEvent) {
    e.preventDefault()
    setBusy(true)
    setError(null)
    try {
      await api('/whatsapp/templates', { method: 'POST', body: form })
      setForm({ ...form, name: '', body: '' })
      onSaved()
    } catch (err) { setError(err instanceof Error ? err.message : 'No se pudo guardar.') } finally { setBusy(false) }
  }
  async function setStatus(t: Template, status: string) {
    try { await api(`/whatsapp/templates/${t.id}`, { method: 'PUT', body: { name: t.name, language: t.language, category: t.category, body: t.body, status } }); onSaved() } catch (err) { setError(err instanceof Error ? err.message : 'No se pudo guardar.') }
  }

  return (
    <>
      <h2 className="mb-1 mt-6 text-sm font-semibold text-ink">Plantillas</h2>
      <p className="mb-2 text-xs text-ink-muted">Se crean y aprueban en Meta; aquí se registra el mismo nombre y texto, y su estado. Solo las «Aprobadas» se pueden enviar. Las variables se escriben {'{{1}}'}, {'{{2}}'}…</p>
      <DataTable label="Plantillas de WhatsApp">
        <thead><tr><th className={th}>Nombre</th><th className={th}>Texto</th><th className={th}>Categoría</th><th className={th}>Estado</th></tr></thead>
        <tbody>
          {rows.length === 0 ? <EmptyRow cols={4}>Todavía no hay plantillas.</EmptyRow> : rows.map((t) => (
            <tr key={t.id} className={tr}>
              <td className={td}>{t.name} <span className="text-xs text-ink-muted">({t.language})</span></td><td className={td}>{t.body}</td><td className={td}>{t.category}</td>
              <td className={td}>
                <select aria-label={`Estado de ${t.name}`} className="rounded border border-line px-2 py-1 text-sm" value={t.status} onChange={(e) => void setStatus(t, e.target.value)}>
                  {Object.entries(TEMPLATE_STATUS).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                </select>
              </td>
            </tr>
          ))}
        </tbody>
      </DataTable>
      <Card className="mt-3 p-4">
        <form onSubmit={(e) => void add(e)} className="grid gap-3 sm:grid-cols-2">
          <TextField id="wat-name" label="Nombre (minúsculas y guion bajo)" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
          <SelectField id="wat-cat" label="Categoría" value={form.category} onChange={(e) => setForm({ ...form, category: e.target.value })}>
            <option value="utility">Utilidad</option><option value="marketing">Marketing</option><option value="authentication">Autenticación</option>
          </SelectField>
          <div className="sm:col-span-2"><TextField id="wat-body" label="Texto de la plantilla" value={form.body} onChange={(e) => setForm({ ...form, body: e.target.value })} /></div>
          <div className="sm:col-span-2"><FormError message={error} /></div>
          <div className="sm:col-span-2"><Button type="submit" loading={busy}>Agregar plantilla</Button></div>
        </form>
      </Card>
    </>
  )
}

/** Cuenta de WhatsApp Business, plantillas y registro de mensajes. Las credenciales nunca se devuelven del servidor. */
export default function WhatsappSettingsPage() {
  const config = useLoad<{ data: WaConfig }>('/whatsapp/settings')
  const templates = useLoad<{ data: Template[] }>('/whatsapp/templates')
  const messages = useLoad<{ data: Message[] }>('/whatsapp/messages')

  return (
    <section>
      <PageHeader title="WhatsApp" description="Cuenta de WhatsApp Business, plantillas aprobadas y registro de mensajes. Nada se envía sin plantilla aprobada, consentimiento del cliente y sin estar marcado «no contactar»." />
      <FormError message={config.error ?? templates.error ?? messages.error} />
      {config.data && !config.data.data.ready && <div className="mb-3"><Alert tone="warning">WhatsApp no está activo: no se envía nada y los intentos quedan registrados como «No enviado».</Alert></div>}
      {config.data && <ConfigForm key={`${config.data.data.phone_number_id}${config.data.data.enabled}${config.data.data.has_access_token}${config.data.data.api_version}`} config={config.data.data} onSaved={config.reload} />}
      <Templates rows={templates.data?.data ?? []} onSaved={templates.reload} />
      <h2 className="mb-2 mt-6 text-sm font-semibold text-ink">Registro de mensajes</h2>
      <DataTable label="Registro de mensajes">
        <thead><tr><th className={th}>Fecha</th><th className={th}>Cliente</th><th className={th}>Mensaje</th><th className={th}>Estado</th><th className={th}>Detalle</th></tr></thead>
        <tbody>
          {(messages.data?.data ?? []).length === 0 ? <EmptyRow cols={5}>Todavía no se ha enviado ningún mensaje.</EmptyRow> : (messages.data?.data ?? []).map((m) => (
            <tr key={m.id} className={tr}><td className={td}>{formatDateTime(m.created_at)}</td><td className={td}>{m.customer ?? '—'}</td><td className={td}>{m.body}</td><td className={td}><Badge>{STATUS[m.status]}</Badge></td><td className={td}>{m.error ?? ''}</td></tr>
          ))}
        </tbody>
      </DataTable>
    </section>
  )
}
