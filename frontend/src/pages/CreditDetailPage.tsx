import { Link, useParams } from 'react-router-dom'
import { Alert, Card, DataTable, EmptyRow, PageHeader, td, th, tr } from '../components/ui'
import { MOVEMENT_LABEL, bucketLabel, type CreditInvoiceDetail } from '../lib/credit'
import { formatMoney } from '../lib/format'
import { formatDay } from '../lib/quotations'
import { useLoad } from '../lib/useLoad'

export default function CreditDetailPage() {
  const { id } = useParams()
  const detail = useLoad<{ data: CreditInvoiceDetail }>(`/credit/invoices/${id}`)
  const d = detail.data?.data

  if (detail.error && !d) return <Alert tone="danger">{detail.error} <Link className="underline" to="/credito">Volver al listado</Link></Alert>

  return (
    <section>
      <PageHeader
        title={d ? `Factura de crédito ${d.document}` : 'Factura de crédito'}
        description={d ? `${d.customer.name} · ${d.company}` : undefined}
        action={<Link className="text-sm text-jge-blue hover:underline" to="/credito">← Volver al listado</Link>}
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
              <tr><th className={th}>Fecha</th><th className={th}>Movimiento</th><th className={`${th} text-right`}>Importe</th><th className={th}>Método y referencia</th><th className={th}>Nota</th><th className={th}>Registró</th></tr>
            </thead>
            <tbody>
              {d.movements.length === 0 ? <EmptyRow cols={6}>Sin movimientos.</EmptyRow> : d.movements.map((m) => (
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
                </tr>
              ))}
            </tbody>
          </DataTable>
        </>
      )}
    </section>
  )
}
