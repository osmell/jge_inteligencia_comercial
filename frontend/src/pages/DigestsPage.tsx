import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../auth/AuthContext'
import { useToast } from '../components/toast'
import { Alert, Badge, Button, Card, DataTable, EmptyRow, FormError, PageHeader, SelectField, td, th, tr } from '../components/ui'
import { api } from '../lib/api'
import { KIND_LABEL, type DigestSummary } from '../lib/digests'
import { formatDateTime } from '../lib/format'
import { formatDay } from '../lib/quotations'
import { useLoad } from '../lib/useLoad'

/** Historial de resúmenes para gerencia (diarios y semanales) de las compañías que la persona ve completas. */
export default function DigestsPage() {
  const { can } = useAuth()
  const toast = useToast()
  const [kind, setKind] = useState('')
  const [companyId, setCompanyId] = useState('')
  const [page, setPage] = useState(1)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const query = new URLSearchParams({ page: String(page) })
  if (kind) query.set('kind', kind)
  if (companyId) query.set('company_id', companyId)
  const list = useLoad<{ data: DigestSummary[]; meta: { has_more: boolean; companies: { id: number; name: string }[] } }>(`/digests?${query}`)
  const companies = list.data?.meta.companies ?? []
  const names = Object.fromEntries(companies.map((c) => [c.id, c.name]))

  async function generate(k: 'daily' | 'weekly') {
    const target = companyId || String(companies[0]?.id ?? '')
    if (!target) return
    setBusy(true)
    setError(null)
    try {
      await api('/digests/generate', { method: 'POST', body: { company_id: Number(target), kind: k } })
      toast.success(`Resumen ${KIND_LABEL[k].toLowerCase()} generado.`)
      list.reload()
    } catch (e) {
      setError(e instanceof Error ? e.message : 'No se pudo generar el resumen.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <section>
      <PageHeader title="Resúmenes" description="El resumen diario (venta de ayer contra su promedio, cartera, cotizaciones, seguimiento e inventario) y el semanal (ventas, metas, cartera, cotizaciones, recomendaciones e inventario) de cada compañía." />
      <Card className="mb-4 p-4">
        <div className="flex flex-wrap items-end gap-3">
          <SelectField id="dg-company" label="Compañía" value={companyId} onChange={(e) => { setCompanyId(e.target.value); setPage(1) }}>
            <option value="">Todas</option>
            {companies.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
          </SelectField>
          <SelectField id="dg-kind" label="Tipo" value={kind} onChange={(e) => { setKind(e.target.value); setPage(1) }}>
            <option value="">Todos</option><option value="daily">Diarios</option><option value="weekly">Semanales</option>
          </SelectField>
          {can('automations.manage') && (
            <>
              <Button variant="secondary" loading={busy} onClick={() => void generate('daily')}>Generar el diario</Button>
              <Button variant="secondary" loading={busy} onClick={() => void generate('weekly')}>Generar el semanal</Button>
            </>
          )}
        </div>
      </Card>
      <FormError message={list.error ?? error} />
      {list.data && companies.length === 0 && <div className="mb-3"><Alert tone="info">Los resúmenes son cifras de toda la compañía, así que solo los ve quien tiene acceso completo a ella.</Alert></div>}

      <DataTable label="Resúmenes">
        <thead><tr><th className={th}>Resumen</th><th className={th}>Tipo</th><th className={th}>Periodo</th><th className={th}>Generado</th></tr></thead>
        <tbody>
          {(list.data?.data ?? []).length === 0 ? <EmptyRow cols={4}>Todavía no hay resúmenes. Se generan solos a las 7:00 (el semanal, los lunes).</EmptyRow> : (list.data?.data ?? []).map((d) => (
            <tr key={d.id} className={tr}>
              <td className={td}><Link className="font-medium text-jge-blue hover:underline" to={`/resumenes/${d.id}`}>{d.title}</Link><div className="text-xs text-ink-muted">{names[d.company_id]}</div></td>
              <td className={td}><Badge>{KIND_LABEL[d.kind]}</Badge></td>
              <td className={td}>{d.period_from === d.period_to ? formatDay(d.period_from) : `${formatDay(d.period_from)} al ${formatDay(d.period_to)}`}</td>
              <td className={td}>{formatDateTime(d.generated_at)}</td>
            </tr>
          ))}
        </tbody>
      </DataTable>
      <div className="mt-3 flex items-center justify-between text-sm">
        <button type="button" className="rounded border border-line px-3 py-1.5 disabled:opacity-50" disabled={page === 1} onClick={() => setPage(page - 1)}>Anterior</button>
        <span className="text-ink-muted">Página {page}</span>
        <button type="button" className="rounded border border-line px-3 py-1.5 disabled:opacity-50" disabled={!list.data?.meta.has_more} onClick={() => setPage(page + 1)}>Siguiente</button>
      </div>
    </section>
  )
}
