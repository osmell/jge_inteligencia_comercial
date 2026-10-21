import { useState } from 'react'
import { Link } from 'react-router-dom'
import { Alert, Badge, Card, DataTable, EmptyRow, FormError, PageHeader, SearchInput, SelectField, TableSkeleton, td, th, tr } from '../components/ui'
import { formatDateTime } from '../lib/format'
import { ALERT_LABEL, SEVERITY_LABEL, type AlertType, type InsightAlertList } from '../lib/insights'
import type { Company } from '../lib/types'
import { useLoad } from '../lib/useLoad'

const TYPES: AlertType[] = ['inactivity_risk', 'sales_drop', 'sales_rise', 'abandoned_line']

/** Alertas de clientes: avisos tempranos y cambios anormales, cada uno con los datos y la regla que lo generó. */
export default function InsightsAlertsPage() {
  const [companyId, setCompanyId] = useState('')
  const [type, setType] = useState<'' | AlertType>('')
  const [severity, setSeverity] = useState('')
  const [text, setText] = useState('')
  const [page, setPage] = useState(1)

  const companies = useLoad<{ data: Company[] }>('/companies')
  const selected = companyId || String(companies.data?.data[0]?.id ?? '')
  const query = new URLSearchParams({ company_id: selected, page: String(page) })
  if (type) query.set('type', type)
  if (severity) query.set('severity', severity)
  if (text.trim()) query.set('q', text.trim())
  const alerts = useLoad<InsightAlertList>(`/insights/alerts?${query}`, { enabled: selected !== '' })
  const rows = alerts.data?.data ?? []
  const counts = alerts.data?.meta.counts

  return (
    <section>
      <PageHeader
        title="Alertas de clientes"
        description="Clientes que conviene revisar: se enfriaron respecto a su propio ritmo, cambiaron mucho su compra o dejaron de comprar una categoría. Son recomendaciones, no decisiones automáticas."
        action={<Link className="text-sm text-jge-blue hover:underline" to="/inteligencia">← Inteligencia</Link>}
      />
      <Card className="mb-4 p-4">
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <SelectField id="al-company" label="Compañía" value={selected} onChange={(e) => { setCompanyId(e.target.value); setPage(1) }}>
            {(companies.data?.data ?? []).map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
          </SelectField>
          <SelectField id="al-severity" label="Gravedad" value={severity} onChange={(e) => { setSeverity(e.target.value); setPage(1) }}>
            <option value="">Todas</option><option value="high">Alta</option><option value="medium">Media</option><option value="low">Baja</option>
          </SelectField>
          <SearchInput label="Buscar cliente" value={text} onChange={(v) => { setText(v); setPage(1) }} placeholder="Nombre o código" />
        </div>
      </Card>

      <div className="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4" aria-label="Alertas por tipo">
        {TYPES.map((t) => (
          <button key={t} type="button" aria-pressed={type === t} onClick={() => { setType(type === t ? '' : t); setPage(1) }}
            className={`rounded-xl border p-3 text-left transition-colors ${type === t ? 'border-jge-blue bg-surface-muted' : 'border-line bg-surface hover:bg-surface-muted'}`}>
            <p className="text-xs text-ink-muted">{ALERT_LABEL[t]}</p>
            <p className="tabular mt-0.5 text-xl font-semibold">{counts ? counts[t].toLocaleString('es') : '—'}</p>
          </button>
        ))}
      </div>

      <FormError message={alerts.error} />
      {alerts.data && rows.length === 0 && !type && !severity && !text && <div className="mb-3"><Alert tone="info">No hay alertas abiertas. Las alertas se calculan cada noche con el historial de ventas; si una compañía no tiene historial suficiente, aparece como «Falta historial» en Inteligencia.</Alert></div>}

      <DataTable label="Alertas de clientes">
        <thead><tr><th className={th}>Cliente</th><th className={th}>Alerta</th><th className={th}>Gravedad</th><th className={th}>Detectada</th></tr></thead>
        <tbody>
          {alerts.loading && !alerts.data ? <TableSkeleton cols={4} /> : rows.length === 0 ? <EmptyRow cols={4}>No hay alertas con estos filtros.</EmptyRow> : rows.map((a) => (
            <tr key={a.id} className={tr}>
              <td className={td}>
                <Link className="font-medium text-jge-blue hover:underline" to={`/clientes/ficha?codigo=${encodeURIComponent(a.code)}&compania=${selected}`}>{a.name}</Link>
                <div className="max-w-xl text-xs text-ink-muted">{a.explanation}</div>
              </td>
              <td className={td}>{ALERT_LABEL[a.type]}</td>
              <td className={td}><Badge>{SEVERITY_LABEL[a.severity]}</Badge></td>
              <td className={td}>{formatDateTime(a.first_detected_at)}<div className="text-xs text-ink-muted">Vigente al {formatDateTime(a.last_detected_at)}</div></td>
            </tr>
          ))}
        </tbody>
      </DataTable>
      <div className="mt-3 flex items-center justify-between text-sm">
        <button type="button" className="rounded border border-line px-3 py-1.5 disabled:opacity-50" disabled={page === 1} onClick={() => setPage(page - 1)}>Anterior</button>
        <span className="text-ink-muted">Página {page}</span>
        <button type="button" className="rounded border border-line px-3 py-1.5 disabled:opacity-50" disabled={!alerts.data?.meta.has_more} onClick={() => setPage(page + 1)}>Siguiente</button>
      </div>
    </section>
  )
}
