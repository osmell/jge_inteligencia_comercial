import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../auth/AuthContext'
import { ApiError, api } from '../lib/api'
import { PROMISE_LABEL, bucketLabel, type CustomerCreditSummary, type PaymentPromiseRow } from '../lib/credit'
import { formatMoney } from '../lib/format'
import { formatDay } from '../lib/quotations'
import { useLoad } from '../lib/useLoad'
import CustomerCreditScore from './CustomerCreditScore'
import PromiseModal from './PromiseModal'
import { useToast } from './toast'
import { Button, Card, FormActions, FormError, Modal, TextAreaField } from './ui'

/** Crédito del cliente en su ficha: saldo por tramo y devoluciones vigentes (notas de crédito), aparte de las ventas. */
export default function CustomerCredit({ companyId, code, name, netTotal }: { companyId: string; code: string; name: string; netTotal: string }) {
  const { can } = useAuth()
  const toast = useToast()
  const params = new URLSearchParams({ company_id: companyId, code })
  const summary = useLoad<{ data: CustomerCreditSummary }>(`/credit/customer?${params}`)
  const promises = useLoad<{ data: PaymentPromiseRow[] }>(`/credit/promises?${params}&status=all`)
  const [creating, setCreating] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [cancelling, setCancelling] = useState<number | null>(null)
  const [reason, setReason] = useState('')
  const [saving, setSaving] = useState(false)
  const s = summary.data?.data
  const returns = s?.returns
  const rows = promises.data?.data ?? []

  async function cancel() {
    if (cancelling === null) return
    setSaving(true)
    setError(null)
    try {
      await api(`/credit/promises/${cancelling}/cancel`, { method: 'POST', body: { reason: reason.trim() } })
      toast.success('Promesa cancelada.')
      setCancelling(null)
      setReason('')
      promises.reload()
    } catch (e) {
      setError(e instanceof ApiError ? Object.values(e.errors).flat()[0] ?? e.message : 'No se pudo cancelar.')
    } finally {
      setSaving(false)
    }
  }

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

          <CustomerCreditScore companyId={companyId} code={code} />

          <div className="mt-3 border-t border-line pt-3">
            <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
              <h3 className="text-sm font-semibold">Promesas de pago</h3>
              {can('credit.promises') && s.open_invoices > 0 && <Button small variant="secondary" onClick={() => setCreating(true)}>Registrar promesa</Button>}
            </div>
            <FormError message={error ?? promises.error} />
            {rows.length === 0 ? <p className="text-sm text-ink-muted">Sin promesas de pago.</p> : (
              <ul className="space-y-1" aria-label="Promesas de pago del cliente">
                {rows.map((p) => (
                  <li key={p.id} className="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
                    <span className="font-medium">{PROMISE_LABEL[p.status]}</span>
                    <span className="tabular">{formatMoney(p.amount)} para el {formatDay(p.promised_on)}</span>
                    {p.status !== 'cancelled' && Number(p.paid) > 0 && <span className="text-ink-muted">Abonado {formatMoney(p.paid)}</span>}
                    {p.status === 'cancelled' && p.cancel_reason && <span className="text-ink-muted">{p.cancel_reason}</span>}
                    {p.note && <span className="text-ink-muted">{p.note}</span>}
                    {can('credit.promises') && (p.status === 'open' || p.status === 'broken') && <Button small variant="secondary" onClick={() => { setReason(''); setError(null); setCancelling(p.id) }} aria-label={`Cancelar promesa de ${formatMoney(p.amount)} para el ${p.promised_on}`}>Cancelar</Button>}
                  </li>
                ))}
              </ul>
            )}
          </div>
        </Card>
      )}
      {cancelling !== null && (
        <Modal title="Cancelar promesa de pago" onClose={() => setCancelling(null)}>
          <form onSubmit={(e) => { e.preventDefault(); void cancel() }} className="space-y-3">
            <TextAreaField id="promise-cancel" label="Motivo de la cancelación" value={reason} maxLength={500} onChange={(e) => setReason(e.target.value)} />
            <FormError message={error} />
            <FormActions saving={saving} onCancel={() => setCancelling(null)} />
          </form>
        </Modal>
      )}
      {creating && s && (
        <PromiseModal companyId={Number(companyId)} code={code} name={name} balance={formatMoney(s.totals.total)} onClose={() => setCreating(false)} onSaved={() => { setCreating(false); promises.reload() }} />
      )}
    </section>
  )
}
