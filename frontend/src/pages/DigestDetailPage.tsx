import { Link, useParams } from 'react-router-dom'
import { Alert, Card, DataTable, PageHeader, td, th, tr } from '../components/ui'
import { KIND_LABEL, type DigestDetail, type DigestMetric, type MetricFormat } from '../lib/digests'
import { formatDateTime, formatMoney } from '../lib/format'
import { useLoad } from '../lib/useLoad'

function show(value: number | string | null, format: MetricFormat): string {
  if (value === null || value === undefined || value === '') return '—'
  if (format === 'money') return formatMoney(value)
  if (format === 'pct') return `${Number(value).toLocaleString('en-US', { maximumFractionDigits: 1 })} %`
  if (format === 'int') return Number(value).toLocaleString('en-US')
  return String(value)
}

const TONE = { good: 'text-success', bad: 'text-danger', neutral: '' } as const

function Metric({ m }: { m: DigestMetric }) {
  return (
    <Card className="p-4">
      <p className="text-sm text-ink-muted">{m.label}</p>
      <p className={`tabular mt-0.5 text-2xl font-semibold tracking-tight ${TONE[m.tone ?? 'neutral']}`}>{show(m.value, m.format)}</p>
      {m.hint && <p className="mt-1 text-xs text-ink-muted">{m.hint}</p>}
    </Card>
  )
}

/** Un resumen para gerencia tal como se generó: secciones con sus cifras y tablas. */
export default function DigestDetailPage() {
  const { id } = useParams()
  const detail = useLoad<{ data: DigestDetail }>(`/digests/${id}`)
  const d = detail.data?.data

  if (detail.error && !d) return <Alert tone="danger">{detail.error} <Link className="underline" to="/resumenes">Volver a los resúmenes</Link></Alert>

  return (
    <section>
      <PageHeader title={d?.title ?? 'Resumen'} description={d ? `Resumen ${KIND_LABEL[d.kind].toLowerCase()} generado el ${formatDateTime(d.generated_at)}. Las cifras quedan tal como estaban al generarse.` : undefined} action={<Link className="text-sm text-jge-blue hover:underline" to="/resumenes">← Resúmenes</Link>} />
      {d?.sections.map((s) => (
        <section key={s.key} aria-label={s.title} className="mb-6">
          <h2 className="mb-2 text-sm font-semibold text-ink">{s.title}</h2>
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">{s.metrics.map((m) => <Metric key={m.label} m={m} />)}</div>
          {(s.tables ?? []).map((t) => (
            <div key={t.title} className="mt-3">
              <DataTable label={t.title}>
                <thead><tr>{t.columns.map((c) => <th key={c.key} className={`${th} ${c.format === 'text' ? '' : 'text-right'}`}>{c.label}</th>)}</tr></thead>
                <tbody>
                  {t.rows.map((r, i) => <tr key={i} className={tr}>{t.columns.map((c) => <td key={c.key} className={`${td} ${c.format === 'text' ? '' : 'tabular text-right'}`}>{show(r[c.key], c.format)}</td>)}</tr>)}
                </tbody>
              </DataTable>
            </div>
          ))}
        </section>
      ))}
    </section>
  )
}
