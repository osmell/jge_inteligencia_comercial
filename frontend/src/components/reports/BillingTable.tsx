import { useState } from 'react'
import { formatDate } from '../../lib/customers'
import { formatMoney } from '../../lib/format'
import type { BillingDetail } from '../../lib/reports'
import { useLoad } from '../../lib/useLoad'
import { Button, DataTable, EmptyRow, FormError, TableSkeleton, td, th, tr, Th } from '../ui'

/**
 * Detalle de facturación de un vendedor, operador o sucursal: cada documento del periodo, para contrastarlo con la cifra del reporte.
 * `path` es el recurso del evaluado (`/reports/sellers/3/documents`) y `query` el periodo y los filtros del reporte.
 */
export default function BillingTable({ path, query, label }: { path: string; query: string; label: string }) {
  const [page, setPage] = useState(1)
  const detail = useLoad<{ data: BillingDetail }>(`${path}?${query}&page=${page}`)
  const data = detail.data?.data

  return (
    <>
      <FormError message={detail.error} />
      {data && (
        <p className="mb-3 text-sm text-ink-muted">
          {data.totals.documents.toLocaleString('en-US')} documentos · venta neta <span className="tabular font-medium text-ink">{formatMoney(data.totals.net_total)}</span> ·
          IVA <span className="tabular">{formatMoney(data.totals.tax_total)}</span> · total <span className="tabular">{formatMoney(data.totals.grand_total)}</span>
        </p>
      )}
      <DataTable label={label}>
        <thead>
          <tr>
            <th className={th}>Documento</th>
            <th className={th}>Fecha</th>
            <th className={th}>Cliente</th>
            <th className={th}>Sucursal</th>
            <Th info={{ text: 'Vendedor del documento.' }}>Vendedor</Th>
            <Th info={{ text: 'Operador que facturó el documento.' }}>Facturó</Th>
            <Th right info={{ text: 'Líneas del documento.' }}>Líneas</Th>
            <Th right info="sales_net">Venta neta</Th>
            <Th right info="sales_grand">Total</Th>
          </tr>
        </thead>
        <tbody>
          {detail.loading && !data && <TableSkeleton cols={9} />}
          {data && data.rows.length === 0 && <EmptyRow cols={9}>No hay documentos en el periodo.</EmptyRow>}
          {data?.rows.map((d) => (
            <tr key={d.id} className={tr}>
              <td className={`${td} font-medium`}>{d.document}</td>
              <td className={td}>{formatDate(d.issued_on)}</td>
              <td className={td}>{d.customer_name}<span className="block text-xs text-ink-muted">{d.customer_code}</span></td>
              <td className={td}>{d.branch}</td>
              <td className={td}>{d.seller}</td>
              <td className={td}>{d.operator}</td>
              <td className={`${td} tabular text-right`}>{d.lines}</td>
              <td className={`${td} tabular text-right`}>{formatMoney(d.net_total)}</td>
              <td className={`${td} tabular text-right`}>{formatMoney(d.grand_total)}</td>
            </tr>
          ))}
        </tbody>
      </DataTable>
      {data && data.last_page > 1 && (
        <div className="mt-3 flex items-center justify-between text-sm text-ink-muted">
          <span>Página {data.page} de {data.last_page}</span>
          <div className="flex gap-2">
            <Button variant="secondary" disabled={page === 1 || detail.loading} onClick={() => setPage((p) => p - 1)}>Anterior</Button>
            <Button variant="secondary" disabled={page >= data.last_page || detail.loading} onClick={() => setPage((p) => p + 1)}>Siguiente</Button>
          </div>
        </div>
      )}
    </>
  )
}
