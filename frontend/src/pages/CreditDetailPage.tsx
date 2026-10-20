import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useAuth } from '../auth/AuthContext'
import CreditSettlementModal, { type SettlementKind } from '../components/CreditSettlementModal'
import { useToast } from '../components/toast'
import { Alert, Button, Card, DataTable, EmptyRow, FormActions, FormError, Modal, PageHeader, TextAreaField, td, th, tr } from '../components/ui'
import { ApiError, api } from '../lib/api'
import { MOVEMENT_LABEL, bucketLabel, type CreditInvoiceDetail, type CreditMovementRow } from '../lib/credit'
import { formatMoney } from '../lib/format'
import { formatDay } from '../lib/quotations'
import { useLoad } from '../lib/useLoad'

export default function CreditDetailPage() {
  const { id } = useParams()
  const detail = useLoad<{ data: CreditInvoiceDetail }>(`/credit/invoices/${id}`)
  const d = detail.data?.data
  const { can } = useAuth()
  const toast = useToast()
  const [settle, setSettle] = useState<SettlementKind | null>(null)
  const [reversing, setReversing] = useState<CreditMovementRow | null>(null)
  const [reason, setReason] = useState('')
  const [reverseError, setReverseError] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)

  const canReverse = (m: CreditMovementRow) => !m.reversed && ((m.type === 'payment' && can('credit.payments')) || (m.type === 'credit_note' && can('credit.notes')))
  const hasBalance = d !== undefined && Number(d.balance) > 0

  async function reverse() {
    if (!reversing) return
    setSaving(true)
    setReverseError(null)
    try {
      await api(`/credit/movements/${reversing.id}/reverse`, { method: 'POST', body: { note: reason.trim() } })
      toast.success(reversing.type === 'credit_note' ? 'Nota de crédito anulada.' : 'Abono anulado.')
      setReversing(null)
      setReason('')
      detail.reload()
    } catch (e) {
      setReverseError(e instanceof ApiError ? Object.values(e.errors).flat()[0] ?? e.message : e instanceof Error ? e.message : 'No se pudo anular.')
    } finally {
      setSaving(false)
    }
  }

  if (detail.error && !d) return <Alert tone="danger">{detail.error} <Link className="underline" to="/credito">Volver al listado</Link></Alert>

  return (
    <section>
      <PageHeader
        title={d ? `Factura de crédito ${d.document}` : 'Factura de crédito'}
        description={d ? `${d.customer.name} · ${d.company}` : undefined}
        action={(
          <div className="flex flex-wrap items-center gap-2">
            {hasBalance && can('credit.payments') && <Button small onClick={() => setSettle('payment')}>Registrar abono</Button>}
            {hasBalance && can('credit.notes') && <Button small variant="secondary" onClick={() => setSettle('credit_note')}>Registrar nota de crédito</Button>}
            <Link className="text-sm text-jge-blue hover:underline" to="/credito">← Volver al listado</Link>
          </div>
        )}
      />

      {d && (
        <>
          {!d.sales_linked && <div className="mb-3"><Alert tone="info">Esta factura todavía no está enlazada con una factura de venta cargada; se enlazará cuando se importen las ventas.</Alert></div>}

          <Card className="mb-4 p-4">
            <dl className="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2 lg:grid-cols-4">
              <div><dt className="text-ink-muted">Fecha</dt><dd className="font-medium">{formatDay(d.issued_on)}</dd></div>
              <div><dt className="text-ink-muted">Sucursal</dt><dd className="font-medium">{d.branch}</dd></div>
              <div><dt className="text-ink-muted">Vendedor</dt><dd className="font-medium">{d.seller}</dd></div>
              <div><dt className="text-ink-muted">Antigüedad</dt><dd className="font-medium">{d.bucket === null ? 'Pagada' : `${bucketLabel(d.bucket)} (${d.age_days} días)`}</dd></div>
              <div><dt className="text-ink-muted">Subtotal</dt><dd className="tabular font-medium">{formatMoney(d.subtotal)}</dd></div>
              <div><dt className="text-ink-muted">Descuento</dt><dd className="tabular font-medium">{formatMoney(d.discount_total)}</dd></div>
              <div><dt className="text-ink-muted">Impuesto</dt><dd className="tabular font-medium">{formatMoney(d.tax_total)}</dd></div>
              <div><dt className="text-ink-muted">Total</dt><dd className="tabular font-medium">{formatMoney(d.total)}</dd></div>
              <div><dt className="text-ink-muted">Abonado y acreditado</dt><dd className="tabular font-medium">{formatMoney(d.paid)}</dd></div>
              <div><dt className="text-ink-muted">Saldo</dt><dd className="tabular text-lg font-semibold">{formatMoney(d.balance)}</dd></div>
            </dl>
          </Card>

          <h2 className="mb-2 text-sm font-semibold text-ink">Movimientos</h2>
          <p className="mb-2 text-xs text-ink-muted">El saldo es la suma de estos movimientos. No se editan ni se borran: un error se corrige con una anulación.</p>
          <DataTable label="Movimientos de la factura">
            <thead>
              <tr><th className={th}>Fecha</th><th className={th}>Movimiento</th><th className={`${th} text-right`}>Importe</th><th className={th}>Método y referencia</th><th className={th}>Nota</th><th className={th}>Registró</th><th className={th}><span className="sr-only">Acciones</span></th></tr>
            </thead>
            <tbody>
              {d.movements.length === 0 ? <EmptyRow cols={7}>Sin movimientos.</EmptyRow> : d.movements.map((m) => (
                <tr key={m.id} className={tr}>
                  <td className={td}>{formatDay(m.occurred_on)}</td>
                  <td className={td}>
                    {MOVEMENT_LABEL[m.type]}
                    {m.reversed && <div className="text-xs font-medium text-danger">Anulado</div>}
                  </td>
                  <td className={`${td} tabular text-right`}>{formatMoney(m.amount)}</td>
                  <td className={td}>{[m.method, m.reference].filter(Boolean).join(' · ') || '—'}</td>
                  <td className={td}>{m.note ?? '—'}</td>
                  <td className={td}>{m.user ?? '—'}</td>
                  <td className={td}>{canReverse(m) && <Button small variant="secondary" onClick={() => { setReverseError(null); setReason(''); setReversing(m) }}>Anular</Button>}</td>
                </tr>
              ))}
            </tbody>
          </DataTable>

          {settle && (
            <CreditSettlementModal
              kind={settle} companyId={d.company_id} code={d.customer.code} name={d.customer.name} firstInvoiceId={d.id}
              onClose={() => setSettle(null)} onSaved={() => { setSettle(null); detail.reload() }}
            />
          )}
          {reversing && (
            <Modal title={reversing.type === 'credit_note' ? 'Anular nota de crédito' : 'Anular abono'} onClose={() => setReversing(null)}>
              <form onSubmit={(e) => { e.preventDefault(); void reverse() }} className="space-y-3">
                <p className="text-sm text-ink-muted">
                  {reversing.type === 'credit_note' ? 'Se anulará la nota completa, en todas las facturas a las que se aplicó.' : 'Se anulará este abono.'} El movimiento original se conserva y queda marcado como anulado.
                </p>
                <TextAreaField id="reverse-note" label="Motivo de la anulación" value={reason} maxLength={500} onChange={(e) => setReason(e.target.value)} />
                <FormError message={reverseError} />
                <FormActions saving={saving} onCancel={() => setReversing(null)} />
              </form>
            </Modal>
          )}
        </>
      )}
    </section>
  )
}
