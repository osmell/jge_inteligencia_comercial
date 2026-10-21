import { useState, type FormEvent } from 'react'
import { useToast } from '../components/toast'
import { Alert, Badge, Button, Card, CheckField, DataTable, EmptyRow, FormError, PageHeader, SelectField, TextField, td, th, tr } from '../components/ui'
import { api } from '../lib/api'
import { formatDateTime } from '../lib/format'
import { useLoad } from '../lib/useLoad'

interface MailConfig {
  enabled: boolean
  host: string | null
  port: number
  encryption: 'starttls' | 'ssl' | 'none'
  username: string | null
  has_password: boolean
  from_address: string | null
  from_name: string | null
  ready: boolean
}

interface MailEntry {
  id: number
  to: string | null
  kind: string
  subject: string
  status: 'pending' | 'sending' | 'sent' | 'failed' | 'not_sent'
  attempt: number
  error: string | null
  created_at: string
}

const STATUS = { pending: 'Pendiente', sending: 'Enviando', sent: 'Enviado', failed: 'Falló', not_sent: 'No enviado' } as const

function ConfigForm({ config, onSaved }: { config: MailConfig; onSaved: () => void }) {
  const toast = useToast()
  const [form, setForm] = useState({ enabled: config.enabled, host: config.host ?? '', port: String(config.port), encryption: config.encryption, username: config.username ?? '', password: '', from_address: config.from_address ?? '', from_name: config.from_name ?? '' })
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [testTo, setTestTo] = useState('')
  const set = (k: keyof typeof form, v: string | boolean) => setForm((f) => ({ ...f, [k]: v }))

  async function save(e: FormEvent) {
    e.preventDefault()
    setBusy(true)
    setError(null)
    try {
      await api('/mail/settings', { method: 'PUT', body: { ...form, port: Number(form.port) } })
      toast.success('Configuración de correo guardada.')
      set('password', '')
      onSaved()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'No se pudo guardar.')
    } finally {
      setBusy(false)
    }
  }

  async function test() {
    setBusy(true)
    setError(null)
    try {
      const r = await api<{ data: MailEntry }>('/mail/test', { method: 'POST', body: { to: testTo } })
      if (r.data.status === 'sent') toast.success('Correo de prueba enviado.')
      else setError(r.data.error ?? 'No se pudo enviar el correo de prueba.')
      onSaved()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'No se pudo enviar.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <Card className="p-4">
      <form onSubmit={(e) => void save(e)} className="grid gap-3 sm:grid-cols-2">
        <div className="sm:col-span-2"><CheckField label="Enviar correos desde el sistema" checked={form.enabled} onChange={(e) => set('enabled', e.target.checked)} /></div>
        <TextField id="mail-host" label="Servidor (SMTP)" value={form.host} onChange={(e) => set('host', e.target.value)} />
        <TextField id="mail-port" label="Puerto" type="number" value={form.port} onChange={(e) => set('port', e.target.value)} />
        <SelectField id="mail-enc" label="Cifrado" value={form.encryption} onChange={(e) => set('encryption', e.target.value)}>
          <option value="starttls">STARTTLS (587)</option><option value="ssl">SSL/TLS (465)</option><option value="none">Sin cifrado</option>
        </SelectField>
        <TextField id="mail-user" label="Usuario" autoComplete="off" value={form.username} onChange={(e) => set('username', e.target.value)} />
        <TextField id="mail-pass" label="Contraseña" type="password" autoComplete="new-password" value={form.password} onChange={(e) => set('password', e.target.value)} hint={config.has_password ? 'Hay una contraseña guardada (cifrada). Déjalo vacío para conservarla.' : 'Se guarda cifrada y no se vuelve a mostrar.'} />
        <TextField id="mail-from" label="Correo remitente" type="email" value={form.from_address} onChange={(e) => set('from_address', e.target.value)} />
        <TextField id="mail-from-name" label="Nombre del remitente" value={form.from_name} onChange={(e) => set('from_name', e.target.value)} />
        <div className="sm:col-span-2"><FormError message={error} /></div>
        <div className="sm:col-span-2"><Button type="submit" loading={busy}>Guardar</Button></div>
      </form>
      <div className="mt-4 flex flex-wrap items-end gap-3 border-t border-line pt-4">
        <TextField id="mail-test-to" label="Enviar correo de prueba a" type="email" value={testTo} onChange={(e) => setTestTo(e.target.value)} />
        <Button variant="secondary" loading={busy} disabled={!testTo} onClick={() => void test()}>Enviar prueba</Button>
      </div>
    </Card>
  )
}

/** Servidor de correo y registro de envíos. La contraseña nunca se devuelve del servidor. */
export default function MailSettingsPage() {
  const config = useLoad<{ data: MailConfig }>('/mail/settings')
  const log = useLoad<{ data: MailEntry[] }>('/mail/log')
  const reload = () => { config.reload(); log.reload() }

  return (
    <section>
      <PageHeader title="Correo" description="Servidor desde el que el sistema envía avisos y resúmenes. Cada persona elige en «Avisos» qué recibe por correo." />
      <FormError message={config.error ?? log.error} />
      {config.data && !config.data.data.ready && <div className="mb-3"><Alert tone="warning">El correo no está activo: los avisos que pidan correo quedan en el registro como «No enviado».</Alert></div>}
      {config.data && <ConfigForm key={String(config.data.data.host) + config.data.data.port + config.data.data.has_password + config.data.data.enabled} config={config.data.data} onSaved={reload} />}

      <h2 className="mb-2 mt-6 text-sm font-semibold text-ink">Registro de envíos</h2>
      <DataTable label="Registro de envíos">
        <thead><tr><th className={th}>Fecha</th><th className={th}>Para</th><th className={th}>Asunto</th><th className={th}>Estado</th><th className={th}>Detalle</th></tr></thead>
        <tbody>
          {(log.data?.data ?? []).length === 0 ? <EmptyRow cols={5}>Todavía no se ha enviado ningún correo.</EmptyRow> : (log.data?.data ?? []).map((l) => (
            <tr key={l.id} className={tr}>
              <td className={td}>{formatDateTime(l.created_at)}</td><td className={td}>{l.to ?? '—'}</td><td className={td}>{l.subject}</td>
              <td className={td}><Badge>{STATUS[l.status]}</Badge></td><td className={td}>{l.error ?? (l.attempt > 1 ? `${l.attempt} intentos` : '')}</td>
            </tr>
          ))}
        </tbody>
      </DataTable>
    </section>
  )
}
