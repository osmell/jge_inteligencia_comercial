import { Link } from 'react-router-dom'
import { ALERT_LABEL, SEVERITY_LABEL, type InsightAlertRow } from '../lib/insights'
import { useLoad } from '../lib/useLoad'
import { Badge, Card } from './ui'

/** Alertas abiertas del cliente en su ficha, con el motivo que las generó. */
export default function CustomerAlerts({ companyId, code }: { companyId: string; code: string }) {
  const result = useLoad<{ data: InsightAlertRow[] }>(`/insights/alerts/customer?${new URLSearchParams({ company_id: companyId, code })}`)
  const rows = result.data?.data ?? []
  if (!result.data || rows.length === 0) return null

  return (
    <section aria-labelledby="alerts-title" className="mb-6">
      <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
        <h2 id="alerts-title" className="text-sm font-semibold text-ink">Alertas del cliente</h2>
        <Link className="text-sm text-jge-blue hover:underline" to="/inteligencia/alertas">Ver todas las alertas</Link>
      </div>
      <Card className="p-4 text-sm">
        <ul className="space-y-3" aria-label="Alertas abiertas del cliente">
          {rows.map((a) => (
            <li key={a.id}>
              <p><span className="font-medium">{ALERT_LABEL[a.type]}</span> <Badge>{SEVERITY_LABEL[a.severity]}</Badge></p>
              <p className="mt-0.5 text-ink-muted">{a.explanation}</p>
            </li>
          ))}
        </ul>
        <p className="mt-2 text-xs text-ink-muted">Son recomendaciones calculadas con el historial de ventas; no cambian nada por sí solas.</p>
      </Card>
    </section>
  )
}
