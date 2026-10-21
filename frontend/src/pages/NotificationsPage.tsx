import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useToast } from '../components/toast'
import { Alert, Badge, Button, Card, CheckField, FormError, PageHeader, SelectField } from '../components/ui'
import { api } from '../lib/api'
import { formatDateTime } from '../lib/format'
import { SEVERITY_TEXT, type Notice, type NoticePreferences } from '../lib/notifications'
import { useLoad } from '../lib/useLoad'

function Preferences() {
  const toast = useToast()
  const prefs = useLoad<{ data: NoticePreferences }>('/notifications/preferences')
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const data = prefs.data?.data

  async function save(body: Record<string, unknown>) {
    setBusy(true)
    setError(null)
    try {
      await api('/notifications/preferences', { method: 'PUT', body })
      prefs.reload()
    } catch (e) {
      setError(e instanceof Error ? e.message : 'No se pudo guardar.')
    } finally {
      setBusy(false)
    }
  }

  async function sendTest() {
    setBusy(true)
    setError(null)
    try {
      const r = await api<{ meta: { delivered: boolean } }>('/notifications/test', { method: 'POST' })
      if (r.meta.delivered) toast.success('Te enviamos un aviso de prueba: míralo en la campana.')
      else setError('El aviso de prueba no se entregó porque tienes pausados los avisos o apagados los del sistema.')
    } catch (e) {
      setError(e instanceof Error ? e.message : 'No se pudo enviar el aviso de prueba.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <section aria-labelledby="prefs-title" className="mt-8">
      <h2 id="prefs-title" className="mb-1 text-sm font-semibold text-ink">Preferencias de avisos</h2>
      <p className="mb-3 text-xs text-ink-muted">Elige qué avisos quieres recibir. Solo aparecen los que corresponden a lo que tu rol puede ver.</p>
      <FormError message={prefs.error ?? error} />
      {data && (
        <Card className="space-y-3 p-4">
          <CheckField label="Pausar todos mis avisos" checked={data.paused} disabled={busy} onChange={(e) => void save({ paused: e.target.checked })} />
          <fieldset className="space-y-1" disabled={busy}>
            <legend className="mb-1 text-sm font-medium text-ink">Tipos de aviso</legend>
            {data.types.map((t) => <CheckField key={t.type} label={t.label} checked={t.enabled} onChange={(e) => void save({ types: { [t.type]: e.target.checked } })} />)}
          </fieldset>
          <fieldset className="space-y-1" disabled={busy}>
            <legend className="mb-1 text-sm font-medium text-ink">Recibir también por correo{data.email ? ` (${data.email})` : ''}</legend>
            {!data.email && <p className="text-xs text-ink-muted">Tu usuario no tiene correo registrado; pide a administración que lo agregue.</p>}
            {data.types.map((t) => <CheckField key={t.type} label={`${t.label} por correo`} checked={t.email} onChange={(e) => void save({ email: { [t.type]: e.target.checked } })} />)}
          </fieldset>
          <Button variant="secondary" loading={busy} onClick={() => void sendTest()}>Enviarme un aviso de prueba</Button>
        </Card>
      )}
    </section>
  )
}

/** Todos los avisos de la persona (con filtro) y sus preferencias. */
export default function NotificationsPage() {
  const navigate = useNavigate()
  const [status, setStatus] = useState<'active' | 'new' | 'dismissed'>('active')
  const [page, setPage] = useState(1)
  const [error, setError] = useState<string | null>(null)
  const list = useLoad<{ data: Notice[]; meta: { has_more: boolean } }>(`/notifications?status=${status}&page=${page}`)
  const rows = list.data?.data ?? []

  async function act(n: Notice, what: 'seen' | 'dismiss') {
    setError(null)
    try {
      await api(`/notifications/${n.id}/${what}`, { method: 'POST' })
      list.reload()
    } catch (e) {
      setError(e instanceof Error ? e.message : 'No se pudo actualizar el aviso.')
    }
  }

  return (
    <section>
      <PageHeader title="Avisos" description="Recordatorios y alertas que el sistema te envía sobre tu trabajo. Los vistos y descartados se conservan 90 días." />
      <Card className="mb-4 p-4">
        <SelectField id="notice-status" label="Mostrar" value={status} onChange={(e) => { setStatus(e.target.value as typeof status); setPage(1) }}>
          <option value="active">Vigentes</option><option value="new">Solo nuevos</option><option value="dismissed">Descartados</option>
        </SelectField>
      </Card>
      <FormError message={list.error ?? error} />
      {list.data && rows.length === 0 && <Alert tone="info">No hay avisos en esta vista.</Alert>}
      <ul className="space-y-2" aria-label="Avisos">
        {rows.map((n) => (
          <li key={n.id} className="rounded-xl border border-line bg-surface p-4">
            <div className="flex flex-wrap items-start justify-between gap-2">
              <div>
                <p className={`text-sm ${n.status === 'new' ? 'font-semibold' : ''}`}>
                  {n.status === 'new' && <Badge>Nuevo</Badge>} {n.title}
                </p>
                {n.body && <p className="mt-0.5 text-sm text-ink-muted">{n.body}</p>}
                <p className="mt-1 text-xs text-ink-muted">{n.type_label} · {SEVERITY_TEXT[n.severity]} · {formatDateTime(n.created_at)}</p>
              </div>
              <div className="flex flex-wrap gap-2">
                {n.url && <Button small variant="secondary" onClick={() => { if (n.status === 'new') void act(n, 'seen'); navigate(n.url as string) }} aria-label={`Abrir: ${n.title}`}>Abrir</Button>}
                {n.status === 'new' && <Button small variant="secondary" onClick={() => void act(n, 'seen')} aria-label={`Marcar como visto: ${n.title}`}>Marcar como visto</Button>}
                {n.status !== 'dismissed' && <Button small variant="secondary" onClick={() => void act(n, 'dismiss')} aria-label={`Descartar: ${n.title}`}>Descartar</Button>}
              </div>
            </div>
          </li>
        ))}
      </ul>
      <div className="mt-3 flex items-center justify-between text-sm">
        <button type="button" className="rounded border border-line px-3 py-1.5 disabled:opacity-50" disabled={page === 1} onClick={() => setPage(page - 1)}>Anterior</button>
        <span className="text-ink-muted">Página {page}</span>
        <button type="button" className="rounded border border-line px-3 py-1.5 disabled:opacity-50" disabled={!list.data?.meta.has_more} onClick={() => setPage(page + 1)}>Siguiente</button>
      </div>
      <Preferences />
    </section>
  )
}
