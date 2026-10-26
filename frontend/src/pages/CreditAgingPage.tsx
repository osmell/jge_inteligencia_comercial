import { useState } from 'react'
import { Link } from 'react-router-dom'
import { Button, Card, DataTable, EmptyRow, FormError, PageHeader, SelectField, TableSkeleton, Th, td, th, tr } from '../components/ui'
import { download } from '../lib/api'
import { bucketLabel, type AgingReport } from '../lib/credit'
import { formatMoney } from '../lib/format'
import type { Company } from '../lib/types'
import { useLoad } from '../lib/useLoad'

/** Antigüedad de la cartera por cliente: saldo abierto repartido en los tramos, contados desde la fecha de la factura. */
export default function CreditAgingPage() {
  const [companyId, setCompanyId] = useState('')
  const [exporting, setExporting] = useState(false)
  const [exportError, setExportError] = useState<string | null>(null)
  const companies = useLoad<{ data: Company[] }>('/companies')
  const query = companyId ? `?company_id=${companyId}` : ''
  const report = useLoad<AgingReport>(`/credit/aging${query}`)
  const buckets = report.data?.data.buckets ?? []
  const customers = report.data?.data.customers ?? []
  const totals = report.data?.data.totals

  async function exportCsv() {
    setExporting(true)
    setExportError(null)
    try {
      await download(`/credit/aging/export${query}`, 'antiguedad-de-cartera.csv')
    } catch (e) {
      setExportError(e instanceof Error ? e.message : 'No se pudo exportar.')
    } finally {
      setExporting(false)
    }
  }

  return (
    <section>
      <PageHeader
        title="Antigüedad de cartera"
        description="Saldo por cobrar de cada cliente en los tramos de antigüedad, contados desde la fecha de la factura."
        action={<Link className="text-sm text-jge-blue hover:underline" to="/credito">← Facturas de crédito</Link>}
      />

      <Card className="mb-4 p-4">
        <div className="flex flex-wrap items-end gap-3">
          <SelectField id="aging-company" label="Compañía" value={companyId} onChange={(e) => setCompanyId(e.target.value)}>
            <option value="">Todas</option>
            {(companies.data?.data ?? []).map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
          </SelectField>
          <Button variant="secondary" loading={exporting} onClick={() => void exportCsv()}>Exportar CSV</Button>
        </div>
      </Card>

      <FormError message={report.error ?? exportError} />

      {totals && (
        <div className="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-7" aria-label="Saldo por tramo">
          {buckets.map((b) => (
            <Card key={b} className="p-3"><p className="text-xs text-ink-muted">{bucketLabel(b)}</p><p className="tabular mt-0.5 text-lg font-semibold">{formatMoney(totals[b])}</p></Card>
          ))}
          <Card className="p-3"><p className="text-xs text-ink-muted">Total por cobrar</p><p className="tabular mt-0.5 text-lg font-semibold">{formatMoney(totals.total)}</p></Card>
        </div>
      )}

      <DataTable label="Antigüedad por cliente">
        <thead>
          <tr>
            <th className={th}>Cliente</th>
            {buckets.map((b) => <Th key={b} right info={{ text: `Saldo de las facturas cuya antigüedad cae en el tramo «${bucketLabel(b)}». La antigüedad se cuenta desde la fecha de emisión.` }}>{bucketLabel(b)}</Th>)}
            <Th right info={{ text: 'Saldo total del cliente en todos los tramos.', formula: 'Total = suma de los tramos' }}>Total</Th>
          </tr>
        </thead>
        <tbody>
          {report.loading && !report.data ? <TableSkeleton cols={buckets.length + 2 || 8} /> : customers.length === 0 ? (
            <EmptyRow cols={buckets.length + 2 || 8}>No hay saldos por cobrar.</EmptyRow>
          ) : customers.map((c) => (
            <tr key={`${c.company_id}-${c.code}`} className={tr}>
              <td className={td}>
                <Link className="font-medium text-jge-blue hover:underline" to={`/credito?${new URLSearchParams({ q: c.code })}`}>{c.name}</Link>
                <div className="text-xs text-ink-muted">{c.company} · {c.code} · {c.invoices} {c.invoices === 1 ? 'factura' : 'facturas'} · la más antigua, {c.oldest_days} días</div>
              </td>
              {buckets.map((b) => <td key={b} className={`${td} tabular text-right`}>{Number(c.buckets[b]) === 0 ? '—' : formatMoney(c.buckets[b])}</td>)}
              <td className={`${td} tabular text-right font-semibold`}>{formatMoney(c.total)}</td>
            </tr>
          ))}
        </tbody>
      </DataTable>
      {report.data?.meta.truncated && <p className="mt-2 text-xs text-ink-muted">Se muestran los {customers.length} clientes con mayor saldo de {report.data.meta.customers_total}. La exportación incluye todos.</p>}
    </section>
  )
}
