import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../auth/AuthContext'
import { useToast } from '../components/toast'
import { Alert, Badge, Button, Card, DataTable, EmptyRow, FormError, PageHeader, SearchInput, SelectField, TableSkeleton, td, th, tr } from '../components/ui'
import { api } from '../lib/api'
import { formatDateTime } from '../lib/format'
import type { CrossSellOpportunity, CrossSellRule, CrossSellRun } from '../lib/insights'
import { formatDay } from '../lib/quotations'
import type { Company } from '../lib/types'
import { useLoad } from '../lib/useLoad'

const pct = (v: number) => `${v.toLocaleString('es', { maximumFractionDigits: 1 })} %`

/** Venta cruzada: asociaciones entre categorías y los clientes a los que se les puede ofrecer lo que aún no compran. */
export default function InsightsCrossSellPage() {
  const { can } = useAuth()
  const toast = useToast()
  const [companyId, setCompanyId] = useState('')
  const [target, setTarget] = useState('')
  const [text, setText] = useState('')
  const [term, setTerm] = useState('')
  const [page, setPage] = useState(1)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    const t = setTimeout(() => { setTerm(text.trim()); setPage(1) }, 300)
    return () => clearTimeout(t)
  }, [text])

  const companies = useLoad<{ data: Company[] }>('/companies')
  const selected = companyId || String(companies.data?.data[0]?.id ?? '')
  const rules = useLoad<{ data: CrossSellRule[]; meta: { run: CrossSellRun | null } }>(`/insights/cross-sell?company_id=${selected}`, { enabled: selected !== '' })
  const oppQuery = new URLSearchParams({ company_id: selected, page: String(page) })
  if (target) oppQuery.set('target', target)
  if (term) oppQuery.set('q', term)
  const opportunities = useLoad<{ data: CrossSellOpportunity[]; meta: { has_more: boolean; total: number } }>(`/insights/cross-sell/opportunities?${oppQuery}`, { enabled: selected !== '' })
  const run = rules.data?.meta.run
  const targets = [...new Set((rules.data?.data ?? []).map((r) => r.consequent))].sort()

  async function recalc() {
    setBusy(true)
    setError(null)
    try {
      await api('/insights/cross-sell/recalculate', { method: 'POST', body: { company_id: Number(selected) } })
      toast.success('Venta cruzada recalculada.')
      rules.reload()
      opportunities.reload()
    } catch (e) {
      setError(e instanceof Error ? e.message : 'No se pudo recalcular.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <section>
      <PageHeader
        title="Venta cruzada"
        description="Qué categorías suelen comprarse juntas y a qué clientes se les puede ofrecer lo que todavía no compran. Son sugerencias para el vendedor, no decisiones automáticas."
        action={<Link className="text-sm text-jge-blue hover:underline" to="/inteligencia">← Inteligencia</Link>}
      />
      <Card className="mb-4 p-4">
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <SelectField id="cs-company" label="Compañía" value={selected} onChange={(e) => { setCompanyId(e.target.value); setTarget(''); setPage(1) }}>
            {(companies.data?.data ?? []).map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
          </SelectField>
          <SelectField id="cs-target" label="Qué ofrecer" value={target} onChange={(e) => { setTarget(e.target.value); setPage(1) }}>
            <option value="">Todo</option>
            {targets.map((t) => <option key={t} value={t}>{t}</option>)}
          </SelectField>
          <SearchInput label="Buscar cliente" value={text} onChange={setText} placeholder="Nombre o código" />
          {can('insights.manage') && <div className="flex items-end"><Button variant="secondary" loading={busy} onClick={() => void recalc()}>Recalcular</Button></div>}
        </div>
      </Card>
      <FormError message={rules.error ?? opportunities.error ?? error} />
      {run === null && rules.data && <div className="mb-4"><Alert tone="info">Todavía no se ha calculado la venta cruzada de esta compañía. Se calcula cada noche, o un administrador puede recalcularla.</Alert></div>}
      {run?.status === 'insufficient' && <div className="mb-4"><Alert tone="warning">{run.note ?? 'No hay datos suficientes.'} No se muestran asociaciones hasta que haya historial y facturas suficientes.</Alert></div>}

      {run?.status === 'ok' && (
        <>
          <p className="mb-3 text-xs text-ink-muted">
            Calculado el {formatDateTime(run.computed_at)} con ventas del {formatDay(run.period_from ?? '')} al {formatDay(run.period_to ?? '')}, a nivel de {run.level}: {run.rules} reglas y {run.opportunities} oportunidades entre {run.customers} clientes.
            Una regla se guarda con al menos {run.parameters['cross_sell.min_support_customers']} clientes que la respalden y {run.parameters['cross_sell.min_confidence_pct']} % de confianza.
          </p>

          <h2 className="mb-2 text-sm font-semibold text-ink">Oportunidades por cliente{opportunities.data ? ` (${opportunities.data.meta.total.toLocaleString('es')})` : ''}</h2>
          <DataTable label="Oportunidades de venta cruzada">
            <thead><tr><th className={th}>Cliente</th><th className={th}>Ofrecer</th><th className={th}>Porque compra</th><th className={`${th} text-right`}>Confianza</th><th className={`${th} text-right`}>Clientes que lo respaldan</th></tr></thead>
            <tbody>
              {opportunities.loading && !opportunities.data ? <TableSkeleton cols={5} /> : (opportunities.data?.data ?? []).length === 0 ? <EmptyRow cols={5}>No hay oportunidades con estos filtros.</EmptyRow> : (opportunities.data?.data ?? []).map((o) => (
                <tr key={`${o.code}-${o.suggestion}`} className={tr}>
                  <td className={td}>
                    <Link className="font-medium text-jge-blue hover:underline" to={`/clientes/ficha?codigo=${encodeURIComponent(o.code)}&compania=${selected}`}>{o.name}</Link>
                    <div className="max-w-md text-xs text-ink-muted">{o.explanation}</div>
                  </td>
                  <td className={td}><Badge>{o.suggestion}</Badge></td>
                  <td className={td}>{o.because}</td>
                  <td className={`${td} tabular text-right`}>{pct(o.confidence_pct)}</td>
                  <td className={`${td} tabular text-right`}>{o.support}</td>
                </tr>
              ))}
            </tbody>
          </DataTable>
          <div className="mt-3 flex items-center justify-between text-sm">
            <button type="button" className="rounded border border-line px-3 py-1.5 disabled:opacity-50" disabled={page === 1} onClick={() => setPage(page - 1)}>Anterior</button>
            <span className="text-ink-muted">Página {page}</span>
            <button type="button" className="rounded border border-line px-3 py-1.5 disabled:opacity-50" disabled={!opportunities.data?.meta.has_more} onClick={() => setPage(page + 1)}>Siguiente</button>
          </div>

          <h2 className="mb-2 mt-8 text-sm font-semibold text-ink">Reglas de asociación</h2>
          <DataTable label="Reglas de asociación">
            <thead><tr><th className={th}>Si compra…</th><th className={th}>…también compra</th><th className={`${th} text-right`}>Confianza</th><th className={`${th} text-right`}>Elevación</th><th className={`${th} text-right`}>Clientes</th></tr></thead>
            <tbody>
              {(rules.data?.data ?? []).length === 0 ? <EmptyRow cols={5}>No hay asociaciones que cumplan los umbrales.</EmptyRow> : (rules.data?.data ?? []).map((r) => (
                <tr key={r.id} className={tr} title={r.explanation}>
                  <td className={td}>{r.antecedent}<div className="max-w-md text-xs text-ink-muted">{r.explanation}</div></td>
                  <td className={td}>{r.consequent}</td>
                  <td className={`${td} tabular text-right`}>{pct(r.confidence_pct)}</td>
                  <td className={`${td} tabular text-right`}>{r.lift.toLocaleString('es')}</td>
                  <td className={`${td} tabular text-right`}>{r.both_customers} de {r.antecedent_customers}</td>
                </tr>
              ))}
            </tbody>
          </DataTable>
          <p className="mt-2 text-xs text-ink-muted">Confianza: de los clientes que compran lo primero, el porcentaje que también compra lo segundo. Elevación: cuántas veces más que el cliente promedio (más de 1 indica una asociación real).</p>
        </>
      )}
    </section>
  )
}
