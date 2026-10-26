import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../auth/AuthContext'
import { BarChart } from '../components/charts'
import { useToast } from '../components/toast'
import { Alert, Badge, Button, Card, DataTable, FormError, PageHeader, SelectField, td, th, tr, Th } from '../components/ui'
import { api } from '../lib/api'
import { formatDateTime, formatMoney } from '../lib/format'
import { MONTH_NAMES, type AnalysisRun, type SeasonalIndex, type SeasonalitySummary } from '../lib/insights'
import { formatDay } from '../lib/quotations'
import type { Company } from '../lib/types'
import { useLoad } from '../lib/useLoad'

const idx = (v: number | null) => (v === null ? '—' : v.toLocaleString('es', { maximumFractionDigits: 1 }))

function Verdict({ s, points }: { s: SeasonalIndex; points: number }) {
  if (s.peak === null || s.trough === null) return <span>Sin datos suficientes</span>
  return s.marked
    ? <span>Temporada marcada: mejor mes <strong>{MONTH_NAMES[s.peak - 1]}</strong>, peor <strong>{MONTH_NAMES[s.trough - 1]}</strong> (diferencia de {idx(s.amplitude)} puntos)</span>
    : <span>Sin temporada marcada (diferencia de {idx(s.amplitude)} puntos; se marca desde {points})</span>
}

/** Estacionalidad de la venta por mes del año y comparación con el mismo mes del año anterior. Se activa con 24 meses de historial. */
export default function InsightsSeasonalityPage() {
  const { can } = useAuth()
  const toast = useToast()
  const [companyId, setCompanyId] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const companies = useLoad<{ data: Company[] }>('/companies')
  const selected = companyId || String(companies.data?.data[0]?.id ?? '')
  const result = useLoad<{ data: AnalysisRun<SeasonalitySummary> | null }>(`/insights/seasonality?company_id=${selected}`, { enabled: selected !== '' })
  const run = result.data?.data
  const summary = run?.status === 'ok' ? run.summary : null

  async function recalc() {
    setBusy(true)
    setError(null)
    try {
      await api('/insights/seasonality/recalculate', { method: 'POST', body: { company_id: Number(selected) } })
      toast.success('Estacionalidad recalculada.')
      result.reload()
    } catch (e) {
      setError(e instanceof Error ? e.message : 'No se pudo recalcular.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <section>
      <PageHeader
        title="Estacionalidad"
        description="Cómo cambia la venta a lo largo del año, sin confundir la temporada con el crecimiento. Describe el pasado; no es una predicción."
        action={<Link className="text-sm text-jge-blue hover:underline" to="/inteligencia">← Inteligencia</Link>}
      />
      <Card className="mb-4 p-4">
        <div className="flex flex-wrap items-end gap-3">
          <SelectField id="se-company" label="Compañía" value={selected} onChange={(e) => setCompanyId(e.target.value)}>
            {(companies.data?.data ?? []).map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
          </SelectField>
          {can('insights.manage') && <Button variant="secondary" loading={busy} onClick={() => void recalc()}>Recalcular</Button>}
        </div>
      </Card>
      <FormError message={result.error ?? error} />

      {run === null && result.data && <Alert tone="info">Todavía no se ha calculado. Se calcula cada noche, o un administrador puede recalcularlo.</Alert>}
      {run?.status === 'insufficient' && <Alert tone="warning">{run.note ?? 'Historial insuficiente.'} La estacionalidad necesita al menos 24 meses de ventas; con menos, una temporada se confundiría con una tendencia. Se activa sola cuando haya historial.</Alert>}

      {summary && run && (
        <>
          <p className="mb-3 text-xs text-ink-muted">
            Calculado el {formatDateTime(run.computed_at)} con {summary.months} meses completos de ventas ({summary.first_month} a {summary.last_month}). El índice 100 es el mes promedio; 120 significa 20 % por encima. Primero se quita la tendencia de crecimiento y se promedian los meses iguales de cada año.
          </p>
          <Card className="mb-4 p-4">
            <h2 className="mb-1 text-sm font-semibold text-ink">Toda la compañía</h2>
            <p className="mb-2 text-sm"><Verdict s={summary.company} points={summary.marked_points} /></p>
            <BarChart points={summary.company.indices.map((v, i) => ({ key: MONTH_NAMES[i].slice(0, 3), value: v ?? 0 }))} label="Índice estacional de la venta por mes del año" />
            <DataTable label="Índice estacional por mes">
              <thead><tr><th className={th}>Mes</th><Th right info={{ text: 'Peso del mes frente al mes promedio: 100 = el mes promedio; 120 = 20 % por encima. Se calcula quitando la tendencia de crecimiento, para no confundir temporada con crecimiento.' }}>Índice</Th><Th right info={{ text: 'Cuánto se aparta el mes del mes promedio.', formula: 'Frente al promedio % = Índice − 100' }}>Frente al mes promedio</Th><Th right info={{ text: 'Años con datos completos de ese mes. Cada mes necesita al menos dos.' }}>Años observados</Th></tr></thead>
              <tbody>
                {summary.company.indices.map((v, i) => (
                  <tr key={MONTH_NAMES[i]} className={tr}>
                    <td className={td}>{MONTH_NAMES[i]}{summary.company.peak === i + 1 && <> <Badge>Mejor</Badge></>}{summary.company.trough === i + 1 && <> <Badge>Más bajo</Badge></>}</td>
                    <td className={`${td} tabular text-right font-medium`}>{idx(v)}</td>
                    <td className={`${td} tabular text-right`}>{v === null ? '—' : `${v - 100 > 0 ? '+' : ''}${(v - 100).toLocaleString('es', { maximumFractionDigits: 1 })} %`}</td>
                    <td className={`${td} tabular text-right`}>{summary.company.observations[i]}</td>
                  </tr>
                ))}
              </tbody>
            </DataTable>
          </Card>

          {summary.lines.length > 0 && (
            <section aria-label="Estacionalidad por línea" className="mb-4">
              <h2 className="mb-2 text-sm font-semibold text-ink">Por línea (las de mayor venta)</h2>
              <DataTable label="Índice estacional por línea">
                <thead><tr><th className={th}>Línea</th>{MONTH_NAMES.map((m) => <th key={m} className={`${th} text-right`}>{m.slice(0, 3)}</th>)}<Th info={{ text: 'Mejor y peor mes de la línea y si la temporada es marcada (20 puntos o más de diferencia entre el mejor y el peor).' }}>Lectura</Th></tr></thead>
                <tbody>
                  {summary.lines.map((l) => (
                    <tr key={l.name} className={tr}>
                      <td className={`${td} font-medium`}>{l.name}</td>
                      {l.indices.map((v, i) => <td key={i} className={`${td} tabular text-right`}>{idx(v)}</td>)}
                      <td className={`${td} text-xs`}><Verdict s={l} points={summary.marked_points} /></td>
                    </tr>
                  ))}
                </tbody>
              </DataTable>
            </section>
          )}

          {summary.yoy.length > 0 && (
            <section aria-label="Comparación con el año anterior" className="mb-4">
              <h2 className="mb-2 text-sm font-semibold text-ink">Contra el mismo mes del año anterior</h2>
              <DataTable label="Comparación con el mismo mes del año anterior">
                <thead><tr><th className={th}>Mes</th><Th right info="sales_net">Venta neta</Th><Th right info={{ text: 'Venta neta del mismo mes del año anterior.' }}>Un año antes</Th><Th right info={{ text: 'Cambio frente al mismo mes del año anterior.', formula: 'Cambio % = (Venta del mes − Venta de un año antes) ÷ Venta de un año antes × 100' }}>Cambio</Th></tr></thead>
                <tbody>
                  {summary.yoy.map((y) => (
                    <tr key={y.month} className={tr}>
                      <td className={td}>{formatDay(`${y.month}-01`).replace(/^\d+ /, '')}</td>
                      <td className={`${td} tabular text-right`}>{formatMoney(y.value)}</td>
                      <td className={`${td} tabular text-right`}>{formatMoney(y.previous)}</td>
                      <td className={`${td} tabular text-right`}>{y.change_pct === null ? '—' : `${y.change_pct > 0 ? '+' : ''}${y.change_pct.toLocaleString('es', { maximumFractionDigits: 1 })} %`}</td>
                    </tr>
                  ))}
                </tbody>
              </DataTable>
            </section>
          )}
        </>
      )}
    </section>
  )
}
