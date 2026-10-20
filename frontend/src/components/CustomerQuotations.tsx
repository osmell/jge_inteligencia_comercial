import { Link, useNavigate } from 'react-router-dom'
import { useAuth } from '../auth/AuthContext'
import { formatMoney } from '../lib/format'
import { STATUS_LABEL, formatDay, type QuotationList } from '../lib/quotations'
import { useLoad } from '../lib/useLoad'
import { Button, FormError } from './ui'

/** Cotizaciones del cliente en su ficha (las más recientes) con acceso a crear una nueva. */
export default function CustomerQuotations({ companyId, code, name }: { companyId: string; code: string; name: string }) {
  const { can } = useAuth()
  const navigate = useNavigate()
  const list = useLoad<QuotationList>(`/quotations?${new URLSearchParams({ company_id: companyId, code })}`)
  const rows = list.data?.data ?? []
  const newLink = `/cotizaciones?${new URLSearchParams({ nueva: '1', codigo: code, compania: companyId, cliente: name })}`

  return (
    <section aria-labelledby="quotations-title" className="mb-6">
      <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
        <h2 id="quotations-title" className="text-sm font-semibold text-ink">Cotizaciones</h2>
        {can('interactions.manage') && <Button small variant="secondary" onClick={() => navigate(newLink)}>Nueva cotización</Button>}
      </div>
      <FormError message={list.error} />
      {list.loading && !list.data ? (
        <div className="skeleton h-12 w-full" aria-hidden="true" />
      ) : rows.length === 0 ? (
        <p className="rounded-xl border border-line bg-surface p-4 text-sm text-ink-muted">Este cliente aún no tiene cotizaciones.</p>
      ) : (
        <ul className="space-y-2" aria-label="Cotizaciones del cliente">
          {rows.map((q) => (
            <li key={q.id} className="flex flex-wrap items-center gap-x-4 gap-y-1 rounded-xl border border-line bg-surface p-3 text-sm">
              <Link className="font-medium text-jge-blue hover:underline" to={`/cotizaciones/${q.id}`}>{q.number}</Link>
              <span>{STATUS_LABEL[q.effective_status]}</span>
              <span className="tabular">{formatMoney(q.amount)}</span>
              <span className="text-ink-muted">{q.valid_until ? `Vence ${formatDay(q.valid_until)}` : 'Sin vencimiento'}</span>
              {q.document && <span className="text-ink-muted">Factura {q.document.number}</span>}
            </li>
          ))}
        </ul>
      )}
    </section>
  )
}
