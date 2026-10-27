import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../auth/AuthContext'
import { BarChart } from '../components/charts'
import { InfoTip } from '../components/InfoTip'
import { useToast } from '../components/toast'
import { Alert, Badge, Button, Card, DataTable, FormError, PageHeader, SelectField, td, th, tr, Stat, Th } from '../components/ui'
import { api } from '../lib/api'
import { formatDateTime, formatMoney } from '../lib/format'
import type { AnalysisRun, CohortSummary, ConcentrationBlock, ConcentrationSummary } from '../lib/insights'
import { formatDay } from '../lib/quotations'
import type { Company } from '../lib/types'
import { useLoad } from '../lib/useLoad'

const pct = (v: number | null) => (v === null ? '' : `${v.toLocaleString('en-US', { maximumFractionDigits: 1 })} %`)

function RunNote<S>({ run }: { run: AnalysisRun<S> | null | undefined }) {
  if (run === undefined) return null
  if (run === null) return <Alert tone="info">Todavía no se ha calculado. Se calcula cada noche, o un administrador puede recalcularlo.</Alert>
  if (run.status === 'insufficient') return <Alert tone="warning">{run.note ?? 'No hay datos suficientes.'} No se muestran resultados hasta que haya historial suficiente.</Alert>

  return <p className="text-xs text-ink-muted">Calculado el {formatDateTime(run.computed_at)}{run.period_from ? ` con ventas del ${formatDay(run.period_from)} al ${formatDay(run.period_to ?? run.period_from)}` : ''}.</p>
}

function Block({ title, kind, data }: { title: string; kind: 'clientes' | 'productos'; data: ConcentrationBlock }) {
  return (
    <section aria-label={title} className="mt-6">
      <h2 className="mb-2 text-sm font-semibold text-ink">{title}</h2>
      <div className="mb-3 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <Stat label={kind === 'clientes' ? 'Peso del cliente principal' : 'Peso del producto principal'} info={{ text: 'Parte de la venta de los últimos 12 meses que aporta el principal.', formula: 'Peso % = Venta del principal ÷ Venta total × 100' }} value={pct(data.top1_pct)} />
        <Stat label={`Peso de los ${Math.min(10, data.count)} principales`} info={{ text: 'Parte de la venta de los últimos 12 meses que aportan los 10 principales juntos.', formula: 'Peso % = Venta de los 10 principales ÷ Venta total × 100' }} value={pct(data.top10_pct)} />
        <Stat label="Suman el 80 % de la venta" info={{ text: 'Cuántos hacen falta, de mayor a menor venta, para llegar al 80 % de la venta (curva de Pareto). Entre paréntesis, qué parte del total son.' }} value={`${data.n80.toLocaleString('en-US')} ${kind}`} hint={`${pct(data.n80_pct)} de ${data.count.toLocaleString('en-US')}`} />
        <Stat label="Índice de concentración (HHI)" info={{ text: 'Mide qué tan concentrada está la venta. Va de 0 (muy repartida) a 10,000 (todo en uno).', formula: 'HHI = suma de (Peso % de cada uno)²' }} value={data.hhi.toLocaleString('en-US')} hint="0 a 10.000; más alto = más concentrado" />
      </div>
      <div className="grid gap-4 lg:grid-cols-2">
        <Card className="p-4">
          <h3 className="mb-1 text-sm font-medium">Curva de Pareto</h3>
          <BarChart points={data.curve.map((c) => ({ key: `${c.entities_pct} %`, value: c.sales_pct }))} label={`Porcentaje acumulado de la venta según el porcentaje de ${kind}`} />
          <p className="mt-1 text-xs text-ink-muted">Cada barra: % acumulado de la venta que suma ese % de {kind}, de los mayores a los menores.</p>
        </Card>
        <DataTable label={`Principales ${kind}`}>
          <thead><tr><th className={th}>{kind === 'clientes' ? 'Cliente' : 'Producto'}</th><Th right info="sales_net">Venta neta</Th><Th right info={{ text: 'Parte de la venta de los últimos 12 meses que representa esta fila. La venta de clientes es neta de devoluciones.', formula: 'Peso % = Venta de la fila ÷ Venta total × 100' }}>Peso</Th><Th right info={{ text: 'Suma de los pesos desde el principal hasta esta fila (curva de Pareto).' }}>Acumulado</Th></tr></thead>
          <tbody>
            {data.top.map((t) => (
              <tr key={t.code} className={tr}>
                <td className={td}>{t.name}<div className="text-xs text-ink-muted">{t.code}</div></td>
                <td className={`${td} tabular text-right`}>{formatMoney(t.net)}</td>
                <td className={`${td} tabular text-right`}>{pct(t.share_pct)}</td>
                <td className={`${td} tabular text-right`}>{pct(t.cumulative_pct)}</td>
              </tr>
            ))}
          </tbody>
        </DataTable>
      </div>
    </section>
  )
}

function Cohorts({ summary }: { summary: CohortSummary }) {
  const cols = Array.from({ length: summary.depth + 1 }, (_, k) => k)
  const shade = (v: number | null) => (v === null ? undefined : { backgroundColor: `color-mix(in srgb, var(--color-jge-blue, #1e5aa8) ${Math.round(v * 0.55)}%, transparent)` })

  return (
    <>
      <div className="mb-2 overflow-x-auto">
        <table className="w-full min-w-max text-sm" aria-label="Retención por cohorte">
          <thead>
            <tr className="text-left text-xs text-ink-muted"><th className="py-1 pr-3"><span className="inline-flex items-center gap-1">Primera compra<InfoTip info={{ text: 'Mes en que el cliente compró por primera vez. El primer mes del historial agrupa a clientes que ya existían antes de que hubiera datos y no entra en el promedio.' }} label="Primera compra" /></span></th><th className="py-1 pr-3 text-right">Clientes</th>{cols.map((k) => <th key={k} className="px-2 py-1 text-right"><span className="inline-flex items-center gap-1">Mes {k}<InfoTip info={{ text: `Porcentaje de los clientes del grupo que volvieron a comprar ${k} ${k === 1 ? 'mes' : 'meses'} después de su primera compra. Las celdas que todavía no se pueden observar quedan vacías.`, formula: 'Retención % = Clientes del grupo que compraron en ese mes ÷ Clientes del grupo × 100' }} label={`Mes ${k}`} /></span></th>)}</tr>
          </thead>
          <tbody>
            {summary.cohorts.map((c) => (
              <tr key={c.month} className="border-t border-line">
                <td className="py-1.5 pr-3 font-medium">{c.month}{c.prior && <div className="text-xs font-normal text-ink-muted">Incluye clientes previos al historial</div>}</td>
                <td className="tabular py-1.5 pr-3 text-right">{c.customers}</td>
                {cols.map((k) => <td key={k} className="tabular px-2 py-1.5 text-right" style={c.prior ? undefined : shade(c.retention[k])}>{pct(c.retention[k])}</td>)}
              </tr>
            ))}
            <tr className="border-t-2 border-line font-medium">
              <td className="py-1.5 pr-3">Promedio ponderado</td><td />
              {cols.map((k) => <td key={k} className="tabular px-2 py-1.5 text-right">{pct(summary.average[k])}</td>)}
            </tr>
          </tbody>
        </table>
      </div>
      <p className="text-xs text-ink-muted">Porcentaje de los clientes de la cohorte que volvieron a comprar en cada mes siguiente. La primera cohorte agrupa a clientes que ya existían antes de que hubiera datos, por eso no entra en el promedio; el mes de la última venta está incompleto y no se mide.</p>
    </>
  )
}

/** Concentración de la venta por cliente y producto, y retención por cohortes. Cifras de toda la compañía. */
export default function InsightsConcentrationPage() {
  const { can } = useAuth()
  const toast = useToast()
  const [companyId, setCompanyId] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const companies = useLoad<{ data: Company[] }>('/companies')
  const selected = companyId || String(companies.data?.data[0]?.id ?? '')
  const concentration = useLoad<{ data: AnalysisRun<ConcentrationSummary> | null }>(`/insights/concentration?company_id=${selected}`, { enabled: selected !== '' })
  const cohorts = useLoad<{ data: AnalysisRun<CohortSummary> | null }>(`/insights/cohorts?company_id=${selected}`, { enabled: selected !== '' })
  const conc = concentration.data?.data
  const coh = cohorts.data?.data
  const alertPct = conc?.summary?.alert_pct

  async function recalc() {
    setBusy(true)
    setError(null)
    try {
      await Promise.all((['concentration', 'cohorts'] as const).map((a) => api(`/insights/${a}/recalculate`, { method: 'POST', body: { company_id: Number(selected) } })))
      toast.success('Análisis recalculados.')
      concentration.reload()
      cohorts.reload()
    } catch (e) {
      setError(e instanceof Error ? e.message : 'No se pudo recalcular.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <section>
      <PageHeader
        title="Concentración y cohortes"
        description="Qué tan concentrada está la venta en pocos clientes o productos, y qué porcentaje de los clientes de cada mes de primera compra sigue comprando."
        action={<Link className="text-sm text-jge-blue hover:underline" to="/inteligencia">← Inteligencia</Link>}
      />
      <Card className="mb-4 p-4">
        <div className="flex flex-wrap items-end gap-3">
          <SelectField id="conc-company" label="Compañía" value={selected} onChange={(e) => setCompanyId(e.target.value)}>
            {(companies.data?.data ?? []).map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
          </SelectField>
          {can('insights.manage') && <Button variant="secondary" loading={busy} onClick={() => void recalc()}>Recalcular</Button>}
        </div>
      </Card>
      <FormError message={concentration.error ?? cohorts.error ?? error} />

      <h2 className="mb-1 text-sm font-semibold text-ink">Concentración de la venta</h2>
      <RunNote run={concentration.data ? conc : undefined} />
      {conc?.status === 'ok' && conc.summary && (
        <>
          {conc.summary.customers.alerts.length > 0 && (
            <div className="my-3">
              <Alert tone="warning">
                Dependencia de clientes (informativo): {conc.summary.customers.alerts.map((a) => `${a.name} pesa ${pct(a.share_pct)}`).join('; ')} de la venta neta. El aviso se activa a partir de {alertPct} %. Es una señal para diversificar, no una acción automática.
              </Alert>
            </div>
          )}
          <Block title="Clientes" kind="clientes" data={conc.summary.customers} />
          <Block title="Productos" kind="productos" data={conc.summary.products} />
        </>
      )}

      <section aria-label="Cohortes" className="mt-8">
        <h2 className="mb-1 text-sm font-semibold text-ink">Cohortes de clientes</h2>
        <RunNote run={cohorts.data ? coh : undefined} />
        {coh?.status === 'ok' && coh.summary && (coh.summary.cohorts.length > 0 ? <div className="mt-2"><Cohorts summary={coh.summary} /></div> : <p className="text-sm text-ink-muted">No hay cohortes que mostrar.</p>)}
      </section>
      <p className="mt-6"><Badge>Orientativo</Badge> <span className="text-xs text-ink-muted">Estos análisis describen el historial; no son predicciones ni activan acciones automáticas.</span></p>
    </section>
  )
}
