import { Link } from 'react-router-dom'
import { bucketLabel, type CustomerCreditSummary } from '../lib/credit'
import { formatMoney } from '../lib/format'
import { useLoad } from '../lib/useLoad'
import { Card, FormError } from './ui'

/** Crédito del cliente en su ficha: saldo por tramo y devoluciones vigentes (notas de crédito), aparte de las ventas. */
export default function CustomerCredit({ companyId, code, netTotal }: { companyId: string; code: string; netTotal: string }) {
  const summary = useLoad<{ data: CustomerCreditSummary }>(`/credit/customer?${new URLSearchParams({ company_id: companyId, code })}`)
  const s = summary.data?.data
  const returns = s?.returns

  return (
    <section aria-labelledby="customer-credit-title" className="mb-6">
      <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
        <h2 id="customer-credit-title" className="text-sm font-semibold text-ink">Crédito y devoluciones</h2>
        <Link className="text-sm text-jge-blue hover:underline" to={`/credito?${new URLSearchParams({ q: code, company_id: companyId })}`}>Ver facturas de crédito</Link>
      </div>
      <FormError message={summary.error} />
      {!s ? <div className="skeleton h-16 w-full" aria-hidden="true" /> : (
        <Card className="p-4">
          {s.open_invoices === 0 ? (
            <p className="text-sm text-ink-muted">El cliente no tiene saldo por cobrar.</p>
          ) : (
            <>
              <p className="mb-2 text-sm">
                Saldo por cobrar <span className="tabular font-semibold">{formatMoney(s.totals.total)}</span> en {s.open_invoices} {s.open_invoices === 1 ? 'factura' : 'facturas'}; la más antigua tiene {s.oldest_days} días.
              </p>
              <dl className="grid grid-cols-2 gap-x-6 gap-y-1 text-sm sm:grid-cols-3 lg:grid-cols-6">
                {s.buckets.filter((b) => Number(s.totals[b]) > 0).map((b) => (
                  <div key={b}><dt className="text-ink-muted">{bucketLabel(b)}</dt><dd className="tabular font-medium">{formatMoney(s.totals[b])}</dd></div>
                ))}
              </dl>
            </>
          )}
          <p className="mt-3 border-t border-line pt-3 text-sm">
            Devoluciones (notas de crédito vigentes): <span className="tabular font-semibold">{formatMoney(returns?.subtotal)}</span> sin impuesto
            {returns && returns.count > 0 && <> en {returns.count} {returns.count === 1 ? 'nota' : 'notas'}</>}.
            {returns && returns.count > 0 && <> Venta neta menos devoluciones: <span className="tabular font-semibold">{formatMoney(Number(netTotal) - Number(returns.subtotal))}</span>.</>}
          </p>
          <p className="mt-1 text-xs text-ink-muted">Las devoluciones se muestran aparte: no modifican las ventas importadas ni los rankings.</p>
        </Card>
      )}
    </section>
  )
}
