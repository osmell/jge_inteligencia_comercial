import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../auth/AuthContext'
import { useToast } from '../components/toast'
import { Alert, Badge, Button, Card, DataTable, EmptyRow, FormError, PageHeader, SearchInput, SelectField, TableSkeleton, td, th, tr, Th } from '../components/ui'
import { api, download } from '../lib/api'
import { formatDateTime, formatMoney } from '../lib/format'
import type { RfmList, RfmSegment } from '../lib/insights'
import { formatDay } from '../lib/quotations'
import type { Company } from '../lib/types'
import { useLoad } from '../lib/useLoad'

/** Segmentación RFM por compañía: recencia, frecuencia y valor, con la explicación de cada cliente. */
export default function InsightsSegmentsPage() {
  const { can } = useAuth()
  const toast = useToast()
  const [companyId, setCompanyId] = useState('')
  const [segment, setSegment] = useState<'' | RfmSegment>('')
  const [text, setText] = useState('')
  const [term, setTerm] = useState('')
  const [page, setPage] = useState(1)
  const [busy, setBusy] = useState<'recalc' | 'export' | null>(null)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    const t = setTimeout(() => { setTerm(text.trim()); setPage(1) }, 300)
    return () => clearTimeout(t)
  }, [text])

  const companies = useLoad<{ data: Company[] }>('/companies')
  const selected = companyId || String(companies.data?.data[0]?.id ?? '')
  const query = new URLSearchParams({ company_id: selected, page: String(page) })
  if (segment) query.set('segment', segment)
  if (term) query.set('q', term)
  const list = useLoad<RfmList>(`/insights/rfm?${query}`, { enabled: selected !== '' })
  const rows = list.data?.data ?? []
  const meta = list.data?.meta
  const run = meta?.run ?? null

  async function recalc() {
    setBusy('recalc')
    setError(null)
    try {
      await api('/insights/rfm/recalculate', { method: 'POST', body: { company_id: Number(selected) } })
      toast.success('Segmentación recalculada.')
      list.reload()
    } catch (e) {
      setError(e instanceof Error ? e.message : 'No se pudo recalcular.')
    } finally {
      setBusy(null)
    }
  }

  async function exportCsv() {
    setBusy('export')
    setError(null)
    try {
      await download(`/insights/rfm/export?${new URLSearchParams({ company_id: selected, ...(segment ? { segment } : {}) })}`, 'segmentacion-rfm.csv')
    } catch (e) {
      setError(e instanceof Error ? e.message : 'No se pudo exportar.')
    } finally {
      setBusy(null)
    }
  }

  return (
    <section>
      <PageHeader
        title="Segmentación de clientes (RFM)"
        description="Recencia, frecuencia y valor de cada cliente en los últimos meses, puntuados de 1 a 5 contra los demás clientes de la compañía."
        action={<Link className="text-sm text-jge-blue hover:underline" to="/inteligencia">← Inteligencia</Link>}
      />

      <Card className="mb-4 p-4">
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <SelectField id="rfm-company" label="Compañía" value={selected} onChange={(e) => { setCompanyId(e.target.value); setSegment(''); setPage(1) }}>
            {(companies.data?.data ?? []).map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
          </SelectField>
          <SearchInput label="Buscar cliente" value={text} onChange={setText} placeholder="Nombre o código" />
          <div className="flex items-end gap-2">
            <Button variant="secondary" loading={busy === 'export'} disabled={!run || run.status !== 'ok'} onClick={() => void exportCsv()}>Exportar CSV</Button>
            {can('insights.manage') && <Button variant="secondary" loading={busy === 'recalc'} onClick={() => void recalc()}>Recalcular</Button>}
          </div>
        </div>
      </Card>

      <FormError message={list.error ?? error} />
      {run === null && list.data && <div className="mb-4"><Alert tone="info">Todavía no se ha calculado la segmentación de esta compañía. Se calcula cada noche, o un administrador puede recalcularla.</Alert></div>}
      {run?.status === 'insufficient' && <div className="mb-4"><Alert tone="warning">{run.note ?? 'No hay datos suficientes para segmentar.'} No se muestran segmentos hasta que haya historial suficiente.</Alert></div>}
      {run?.status === 'ok' && (
        <p className="mb-3 text-xs text-ink-muted">
          Calculado el {formatDateTime(run.computed_at)} con ventas del {formatDay(run.period_from ?? '')} al {formatDay(run.period_to ?? '')} ({run.customers} clientes con compras).
          La segmentación comercial es distinta del scoring de crédito y de la clasificación ABC.
        </p>
      )}

      {run?.status === 'ok' && meta && (
        <>
          <div className="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-7" aria-label="Resumen por segmento">
            {meta.segments.map((s) => (
              <button key={s.key} type="button" aria-pressed={segment === s.key} title={s.rule} onClick={() => { setSegment(segment === s.key ? '' : s.key); setPage(1) }}
                className={`rounded-xl border p-3 text-left transition-colors ${segment === s.key ? 'border-jge-blue bg-surface-muted' : 'border-line bg-surface hover:bg-surface-muted'}`}>
                <p className="text-xs text-ink-muted">{s.label}</p>
                <p className="tabular mt-0.5 text-xl font-semibold">{meta.counts[s.key].toLocaleString('es')}</p>
              </button>
            ))}
          </div>
          {segment && <p className="mb-3 text-sm text-ink-muted">{meta.segments.find((s) => s.key === segment)?.rule}</p>}

          <DataTable label="Clientes por segmento">
            <thead>
              <tr>
                <th className={th}>Cliente</th><Th info={{ text: 'Grupo que se asigna al cliente con reglas fijas sobre sus puntuaciones R, F y V: Nuevos, Campeones, Leales, Prometedores, En riesgo, Dormidos o Perdidos.' }}>Segmento</Th><Th info={{ text: 'Fecha de la última compra del cliente.' }}>Última compra</Th><Th right info={{ text: 'Frecuencia: días distintos con compra en los últimos 12 meses.' }}>Días con compra</Th>
                <Th right info={{ text: 'Valor: venta neta menos devoluciones vigentes (notas de crédito no anuladas) en los últimos 12 meses.' }}>Venta neta</Th><Th className="text-center" info={{ text: 'Puntuaciones de 1 a 5 de Recencia (qué tan reciente compró), Frecuencia (cuántos días compró) y Valor (cuánto compró). Se calculan por quintiles entre los clientes de la compañía.' }}>R · F · V</Th>
              </tr>
            </thead>
            <tbody>
              {list.loading && !list.data ? <TableSkeleton cols={6} /> : rows.length === 0 ? <EmptyRow cols={6}>No hay clientes con estos filtros.</EmptyRow> : rows.map((r) => (
                <tr key={r.code} className={tr}>
                  <td className={td}>
                    <Link className="font-medium text-jge-blue hover:underline" to={`/clientes/ficha?codigo=${encodeURIComponent(r.code)}&compania=${selected}`}>{r.name}</Link>
                    <div className="max-w-md text-xs text-ink-muted">{r.explanation}</div>
                  </td>
                  <td className={td}><Badge>{r.segment_label}</Badge></td>
                  <td className={td}>{formatDay(r.last_purchase)}<div className="text-xs text-ink-muted">hace {r.recency_days} días</div></td>
                  <td className={`${td} tabular text-right`}>{r.frequency}</td>
                  <td className={`${td} tabular text-right`}>{formatMoney(r.monetary)}{Number(r.returns) > 0 && <div className="text-xs text-ink-muted">tras {formatMoney(r.returns)} de devoluciones</div>}</td>
                  <td className={`${td} tabular text-center`}>{r.r} · {r.f} · {r.m}</td>
                </tr>
              ))}
            </tbody>
          </DataTable>
          <div className="mt-3 flex items-center justify-between text-sm">
            <button type="button" className="rounded border border-line px-3 py-1.5 disabled:opacity-50" disabled={page === 1} onClick={() => setPage(page - 1)}>Anterior</button>
            <span className="text-ink-muted">Página {page}</span>
            <button type="button" className="rounded border border-line px-3 py-1.5 disabled:opacity-50" disabled={!meta.has_more} onClick={() => setPage(page + 1)}>Siguiente</button>
          </div>
        </>
      )}
    </section>
  )
}
