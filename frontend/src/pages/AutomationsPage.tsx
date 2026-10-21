import { useState } from 'react'
import { useToast } from '../components/toast'
import { Alert, Badge, Button, Card, CheckField, DataTable, EmptyRow, FormError, PageHeader, SelectField, TextField, td, th, tr, type FieldErrors } from '../components/ui'
import { ApiError, api } from '../lib/api'
import { FREQUENCY_LABEL, RUN_STATUS_LABEL, TRIGGER_LABEL, describeResult, type Automation, type AutomationList, type AutomationRunRow } from '../lib/automations'
import { formatDateTime } from '../lib/format'
import { useLoad } from '../lib/useLoad'

function AutomationCard({ a, onChanged, onError }: { a: Automation; onChanged: () => void; onError: (message: string | null) => void }) {
  const toast = useToast()
  const [draft, setDraft] = useState<Record<string, string>>(() => Object.fromEntries(a.params.map((p) => [p.name, String(p.value)])))
  const [errors, setErrors] = useState<FieldErrors>({})
  const [busy, setBusy] = useState<'save' | 'run' | 'toggle' | null>(null)
  const changed = a.params.filter((p) => draft[p.name].trim() !== String(p.value))

  async function save(body: Record<string, unknown>, kind: 'save' | 'toggle') {
    setBusy(kind)
    setErrors({})
    onError(null)
    try {
      await api(`/automations/${a.key}`, { method: 'PUT', body })
      if (kind === 'save') toast.success('Parámetros guardados.')
      onChanged()
    } catch (e) {
      if (e instanceof ApiError) setErrors(e.errors)
      onError(e instanceof Error ? e.message : 'No se pudo guardar.')
    } finally {
      setBusy(null)
    }
  }

  async function runNow() {
    setBusy('run')
    onError(null)
    try {
      await api(`/automations/${a.key}/run`, { method: 'POST' })
      toast.success('Se ejecutó la automatización; el resultado está en el registro.')
      onChanged()
    } catch (e) {
      onError(e instanceof Error ? e.message : 'No se pudo ejecutar.')
    } finally {
      setBusy(null)
    }
  }

  return (
    <Card className="p-4">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div>
          <h3 className="text-sm font-semibold text-ink">{a.label}</h3>
          <p className="mt-0.5 text-sm text-ink-muted">{a.description}</p>
          <p className="mt-1 text-xs text-ink-muted">{FREQUENCY_LABEL[a.frequency]}{a.respect_window ? ' · solo en la ventana de avisos' : ''}</p>
        </div>
        <Badge>{!a.available ? 'En preparación' : a.enabled ? 'Activa' : 'Pausada'}</Badge>
      </div>

      {a.available && (
        <>
          <div className="mt-3"><CheckField label={`Activa: ${a.label}`} checked={a.enabled} disabled={busy !== null} onChange={(e) => void save({ enabled: e.target.checked }, 'toggle')} /></div>
          <form className="mt-2 grid gap-3 sm:grid-cols-2" onSubmit={(e) => { e.preventDefault(); void save({ params: Object.fromEntries(changed.map((p) => [p.name, draft[p.name].trim()])) }, 'save') }}>
            {a.params.map((p) => (
              <TextField key={p.name} id={`${a.key}-${p.name}`} label={`${p.label} (${p.unit})`} inputMode="numeric" value={draft[p.name]} hint={`${p.help} Rango ${p.min} a ${p.max}.`} errors={errors[`params.${p.name}`]} onChange={(e) => setDraft({ ...draft, [p.name]: e.target.value })} />
            ))}
            <div className="flex items-end gap-2 sm:col-span-2">
              <Button type="submit" small loading={busy === 'save'} disabled={changed.length === 0}>Guardar parámetros</Button>
              <Button type="button" small variant="secondary" loading={busy === 'run'} onClick={() => void runNow()} aria-label={`Ejecutar ahora: ${a.label}`}>Ejecutar ahora</Button>
            </div>
          </form>
          <p className="mt-3 text-xs text-ink-muted">
            {a.last_run ? <>Última ejecución: {RUN_STATUS_LABEL[a.last_run.status]} ({TRIGGER_LABEL[a.last_run.trigger].toLowerCase()}), {formatDateTime(a.last_run.started_at)}{a.last_run.error ? ` · ${a.last_run.error}` : ''}</> : 'Todavía no se ha ejecutado.'}
          </p>
        </>
      )}
    </Card>
  )
}

function Settings({ meta, onChanged, onError }: { meta: AutomationList['meta']; onChanged: () => void; onError: (m: string | null) => void }) {
  const toast = useToast()
  const [start, setStart] = useState(String(meta.window.start))
  const [end, setEnd] = useState(String(meta.window.end))
  const [busy, setBusy] = useState(false)

  async function put(body: Record<string, unknown>, ok?: string) {
    setBusy(true)
    onError(null)
    try {
      await api('/automations/settings', { method: 'PUT', body })
      if (ok) toast.success(ok)
      onChanged()
    } catch (e) {
      onError(e instanceof Error ? e.message : 'No se pudo guardar.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <Card className="mb-4 space-y-3 p-4">
      <CheckField label="Automatizaciones activas (interruptor general)" checked={meta.master_enabled} disabled={busy} onChange={(e) => void put({ master_enabled: e.target.checked })} />
      {!meta.master_enabled && <Alert tone="warning">Todas las automatizaciones están pausadas: no se ejecuta ninguna, ni sola ni a mano.</Alert>}
      <form className="grid gap-3 sm:grid-cols-3 sm:items-end" onSubmit={(e) => { e.preventDefault(); void put({ window: { start: Number(start), end: Number(end) } }, 'Ventana guardada.') }}>
        <TextField id="win-start" label="Los avisos se envían desde (hora)" inputMode="numeric" value={start} onChange={(e) => setStart(e.target.value)} />
        <TextField id="win-end" label="hasta (hora)" inputMode="numeric" value={end} hint="De lunes a sábado; los domingos no se envían avisos." onChange={(e) => setEnd(e.target.value)} />
        <Button type="submit" small variant="secondary" loading={busy}>Guardar ventana</Button>
      </form>
      <p className="text-xs text-ink-muted">Si una ejecución falla se reintenta {meta.retry_minutes.length} veces ({meta.retry_minutes.join(' y ')} minutos después) sin duplicar avisos; si sigue fallando queda en el registro con su motivo.</p>
    </Card>
  )
}

/** Administración de las automatizaciones: qué corre, cuándo, con qué parámetros, y el registro de lo ocurrido. */
export default function AutomationsPage() {
  const list = useLoad<AutomationList>('/automations')
  const [filter, setFilter] = useState('')
  const [status, setStatus] = useState('')
  const [error, setError] = useState<string | null>(null)
  const query = new URLSearchParams()
  if (filter) query.set('automation', filter)
  if (status) query.set('status', status)
  const runs = useLoad<{ data: AutomationRunRow[]; meta: { has_more: boolean } }>(`/automations/runs?${query}`)
  const reload = () => { list.reload(); runs.reload() }

  return (
    <section>
      <PageHeader title="Automatizaciones" description="Tareas que el sistema hace solo: recalcular la inteligencia comercial, limpiar avisos viejos y enviar recordatorios y avisos a cada persona. Se pueden pausar, cambiar de hora y revisar lo que pasó en cada ejecución." />
      <FormError message={list.error ?? error} />
      {list.data && <Settings key={`${list.data.meta.master_enabled}-${list.data.meta.window.start}-${list.data.meta.window.end}`} meta={list.data.meta} onChanged={reload} onError={setError} />}

      <div className="grid gap-4 lg:grid-cols-2">
        {(list.data?.data ?? []).map((a) => <AutomationCard key={`${a.key}-${a.enabled}-${a.params.map((p) => p.value).join(',')}`} a={a} onChanged={reload} onError={setError} />)}
      </div>

      <section aria-labelledby="runs-title" className="mt-8">
        <h2 id="runs-title" className="mb-2 text-sm font-semibold text-ink">Registro de ejecuciones</h2>
        <div className="mb-3 flex flex-wrap gap-3">
          <SelectField id="run-filter" label="Automatización" value={filter} onChange={(e) => setFilter(e.target.value)}>
            <option value="">Todas</option>
            {(list.data?.data ?? []).filter((a) => a.available).map((a) => <option key={a.key} value={a.key}>{a.label}</option>)}
          </SelectField>
          <SelectField id="run-status" label="Resultado" value={status} onChange={(e) => setStatus(e.target.value)}>
            <option value="">Todos</option><option value="ok">Correctas</option><option value="failed">Con error</option>
          </SelectField>
        </div>
        <DataTable label="Registro de ejecuciones">
          <thead><tr><th className={th}>Inicio</th><th className={th}>Automatización</th><th className={th}>Origen</th><th className={th}>Resultado</th><th className={th}>Detalle</th></tr></thead>
          <tbody>
            {(runs.data?.data ?? []).length === 0 ? <EmptyRow cols={5}>Todavía no hay ejecuciones.</EmptyRow> : (runs.data?.data ?? []).map((r) => (
              <tr key={r.id} className={tr}>
                <td className={td}>{formatDateTime(r.started_at)}</td>
                <td className={td}>{r.label}</td>
                <td className={td}>{TRIGGER_LABEL[r.trigger]}</td>
                <td className={td}>{RUN_STATUS_LABEL[r.status]}{r.attempt > 1 && <div className="text-xs text-ink-muted">Intento {r.attempt}</div>}</td>
                <td className={`${td} text-xs`}>
                  {r.error ? <span className="text-danger">{r.error}</span> : describeResult(r.result) || '—'}
                  {r.next_retry_at && <div className="text-ink-muted">Se reintentará el {formatDateTime(r.next_retry_at)}</div>}
                </td>
              </tr>
            ))}
          </tbody>
        </DataTable>
      </section>
    </section>
  )
}
