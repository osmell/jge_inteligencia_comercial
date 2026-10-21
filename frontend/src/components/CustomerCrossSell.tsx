import { Link } from 'react-router-dom'
import type { CrossSellOpportunity, CrossSellRun } from '../lib/insights'
import { useLoad } from '../lib/useLoad'
import { Badge, Card } from './ui'

/** Oportunidades de venta cruzada del cliente en su ficha (lo que compran clientes parecidos y él todavía no). */
export default function CustomerCrossSell({ companyId, code }: { companyId: string; code: string }) {
  const result = useLoad<{ data: CrossSellOpportunity[]; meta: { run: CrossSellRun | null } }>(`/insights/cross-sell/customer?${new URLSearchParams({ company_id: companyId, code })}`)
  if (!result.data) return null
  const rows = result.data.data
  const run = result.data.meta.run

  return (
    <section aria-labelledby="crosssell-title" className="mb-6">
      <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
        <h2 id="crosssell-title" className="text-sm font-semibold text-ink">Oportunidades de venta cruzada</h2>
        <Link className="text-sm text-jge-blue hover:underline" to="/inteligencia/venta-cruzada">Ver venta cruzada</Link>
      </div>
      <Card className="p-4 text-sm">
        {rows.length === 0 ? (
          <p className="text-ink-muted">{run?.status === 'ok' ? 'Por ahora no hay una categoría para sugerirle a este cliente.' : 'La venta cruzada todavía no está disponible para esta compañía.'}</p>
        ) : (
          <ul className="space-y-2" aria-label="Oportunidades del cliente">
            {rows.map((o) => (
              <li key={o.suggestion}>
                <p>Ofrecer <Badge>{o.suggestion}</Badge> <span className="text-ink-muted">porque compra {o.because}</span></p>
                <p className="mt-0.5 text-xs text-ink-muted">{o.explanation}</p>
              </li>
            ))}
          </ul>
        )}
        <p className="mt-2 text-xs text-ink-muted">Es una sugerencia para conversar con el cliente; no se registra ningún contacto ni venta por sí sola.</p>
      </Card>
    </section>
  )
}
