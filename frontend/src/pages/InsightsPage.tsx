import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../auth/AuthContext'
import { useToast } from '../components/toast'
import { Alert, Badge, Button, Card, DataTable, EmptyRow, FormError, PageHeader, TextField, td, th, tr, type FieldErrors } from '../components/ui'
import { ApiError, api } from '../lib/api'
import { formatDateTime } from '../lib/format'
import type { InsightCompany, InsightParameter, InsightRunRow } from '../lib/insights'
import { formatDay } from '../lib/quotations'
import { useLoad } from '../lib/useLoad'

function CompanyStatus({ c }: { c: InsightCompany }) {
  return (
    <Card className="p-4">
      <h2 className="text-sm font-semibold text-ink">{c.company}</h2>
      <p className="mt-1 text-sm text-ink-muted">
        {c.first_sale ? <>Ventas del {formatDay(c.first_sale)} al {formatDay(c.last_sale ?? c.first_sale)}: <strong className="text-ink">{c.history_months} meses</strong> de historial y <strong className="text-ink">{c.documents.toLocaleString('es')}</strong> facturas.</> : 'Todavía no hay ventas cargadas.'}
      </p>
      {c.stale && <div className="mt-2"><Alert tone="warning">La última venta es de hace {c.days_since_last_sale} días: los análisis estarían desactualizados.</Alert></div>}
      <ul className="mt-3 space-y-2" aria-label={`Análisis de ${c.company}`}>
        {c.analyses.map((a) => (
          <li key={a.key} className="flex flex-wrap items-start justify-between gap-2 text-sm">
            <div>
              <span className="font-medium">{a.label}</span>
              {a.reason && <div className="text-xs text-ink-muted">{a.reason}</div>}
              {a.last_run && <div className="text-xs text-ink-muted">Último cálculo: {formatDateTime(a.last_run.computed_at)}{a.last_run.period_from ? ` · periodo ${formatDay(a.last_run.period_from)} a ${formatDay(a.last_run.period_to ?? a.last_run.period_from)}` : ''}</div>}
            </div>
            <Badge>{a.available ? 'Disponible' : 'Falta historial'}</Badge>
          </li>
        ))}
      </ul>
    </Card>
  )
}

function Parameters({ manage }: { manage: boolean }) {
  const params = useLoad<{ data: InsightParameter[] }>('/insights/parameters')
  const [version, setVersion] = useState(0)
  const reload = () => { setVersion((v) => v + 1); params.reload() }

  return (
    <section aria-labelledby="params-title" className="mt-8">
      <h2 id="params-title" className="mb-1 text-sm font-semibold text-ink">Parámetros</h2>
      <p className="mb-3 text-xs text-ink-muted">Reglas con las que se calculan los análisis. {manage ? 'Cada cambio queda en Auditoría.' : 'Solo quien tiene el permiso de gestión puede cambiarlos.'}</p>
      <FormError message={params.error} />
      {params.data && <ParametersForm key={`${version}-${JSON.stringify(params.data.data)}`} rows={params.data.data} manage={manage} onReload={reload} />}
    </section>
  )
}

function ParametersForm({ rows, manage, onReload }: { rows: InsightParameter[]; manage: boolean; onReload: () => void }) {
  const toast = useToast()
  const [draft, setDraft] = useState<Record<string, string>>(() => Object.fromEntries(rows.map((p) => [p.key, String(p.value)])))
  const [errors, setErrors] = useState<FieldErrors>({})
  const [saving, setSaving] = useState(false)
  const groups = [...new Set(rows.map((r) => r.group))]
  const changed = rows.filter((r) => draft[r.key] !== undefined && draft[r.key].trim() !== String(r.value))

  async function save() {
    setSaving(true)
    setErrors({})
    try {
      await api('/insights/parameters', { method: 'PUT', body: { values: Object.fromEntries(changed.map((r) => [r.key, draft[r.key].trim()])) } })
      toast.success('Parámetros guardados.')
      onReload()
    } catch (e) {
      setErrors(e instanceof ApiError ? e.errors : { values: [e instanceof Error ? e.message : 'No se pudo guardar.'] })
    } finally {
      setSaving(false)
    }
  }

  return (
    <form onSubmit={(e) => { e.preventDefault(); void save() }}>
      <FormError message={errors.values?.[0] ?? null} />
      {groups.map((g) => (
        <fieldset key={g} className="mb-4">
          <legend className="mb-2 text-sm font-medium text-ink">{g}</legend>
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            {rows.filter((r) => r.group === g).map((r) => (
              <TextField key={r.key} id={`param-${r.key}`} label={`${r.label} (${r.unit})`} inputMode="numeric" value={draft[r.key] ?? ''} disabled={!manage}
                hint={`${r.help} Rango ${r.min} a ${r.max}; por omisión ${r.default}.`} errors={errors[`values.${r.key}`]} onChange={(e) => setDraft({ ...draft, [r.key]: e.target.value })} />
            ))}
          </div>
        </fieldset>
      ))}
      {manage && <div className="flex gap-2"><Button type="submit" loading={saving} disabled={changed.length === 0}>Guardar parámetros</Button><Button type="button" variant="secondary" disabled={changed.length === 0} onClick={onReload}>Descartar cambios</Button></div>}
    </form>
  )
}

export default function InsightsPage() {
  const { can, user } = useAuth()
  const status = useLoad<{ data: InsightCompany[] }>('/insights/status')
  const runs = useLoad<{ data: InsightRunRow[] }>('/insights/runs')
  const companies = status.data?.data ?? []
  const names = Object.fromEntries(companies.map((c) => [c.company_id, c.company]))

  return (
    <section>
      <PageHeader title="Inteligencia comercial" description="Qué análisis avanzados se pueden activar con el historial de ventas disponible, con qué reglas se calculan y cuándo se calcularon por última vez." />
      <div className="mb-4"><Alert tone="info">Un análisis solo se activa con historial suficiente: si faltan datos, aquí se explica qué falta. Las recomendaciones son sugerencias, no decisiones automáticas, y son distintas del scoring de crédito.</Alert></div>
      <FormError message={status.error} />
      <p className="mb-3 flex flex-wrap gap-x-6 gap-y-1 text-sm">
        <Link className="text-jge-blue hover:underline" to="/inteligencia/segmentos">Segmentación de clientes (RFM) →</Link>
        <Link className="text-jge-blue hover:underline" to="/inteligencia/alertas">Recomendaciones →</Link>
        <Link className="text-jge-blue hover:underline" to="/inteligencia/venta-cruzada">Venta cruzada →</Link>
        {user?.role.slug !== 'vendedor' && <Link className="text-jge-blue hover:underline" to="/inteligencia/concentracion">Concentración y cohortes →</Link>}
        {user?.role.slug !== 'vendedor' && <Link className="text-jge-blue hover:underline" to="/inteligencia/estacionalidad">Estacionalidad →</Link>}
      </p>

      <div className="grid gap-4 lg:grid-cols-2" aria-busy={status.loading}>
        {companies.map((c) => <CompanyStatus key={c.company_id} c={c} />)}
        {status.data && companies.length === 0 && <p className="text-sm text-ink-muted">No hay compañías activas.</p>}
      </div>

      <section aria-labelledby="runs-title" className="mt-8">
        <h2 id="runs-title" className="mb-2 text-sm font-semibold text-ink">Últimos cálculos</h2>
        <DataTable label="Registro de cálculos">
          <thead><tr><th className={th}>Fecha de cálculo</th><th className={th}>Compañía</th><th className={th}>Análisis</th><th className={th}>Periodo</th><th className={th}>Resultado</th></tr></thead>
          <tbody>
            {(runs.data?.data ?? []).length === 0 ? <EmptyRow cols={5}>Todavía no se ha calculado ningún análisis.</EmptyRow> : (runs.data?.data ?? []).map((r) => (
              <tr key={r.id} className={tr}>
                <td className={td}>{formatDateTime(r.computed_at)}</td><td className={td}>{names[r.company_id] ?? r.company_id}</td><td className={td}>{r.analysis}</td>
                <td className={td}>{r.period_from ? `${formatDay(r.period_from)} a ${formatDay(r.period_to ?? r.period_from)}` : '—'}</td>
                <td className={td}>{r.status === 'ok' ? 'Calculado' : 'Historial insuficiente'}{r.note && <div className="text-xs text-ink-muted">{r.note}</div>}</td>
              </tr>
            ))}
          </tbody>
        </DataTable>
      </section>

      <Parameters manage={can('insights.manage')} />
    </section>
  )
}
