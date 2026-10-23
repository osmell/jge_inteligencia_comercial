import { useState } from 'react'
import { formatMoney } from '../../lib/format'
import { formatDate } from '../../lib/customers'
import type { BillingDetail } from '../../lib/reports'
import { useLoad } from '../../lib/useLoad'
import { Button, DataTable, EmptyRow, FormError, Modal, TableSkeleton, td, th, tr } from '../ui'

/** Detalle de facturación de un vendedor: cada documento del periodo, para contrastarlo con la cifra del reporte. */
export default function SellerBillingModal({ sellerId, name, query, onClose }: { sellerId: number; name: string; query: string; onClose: () => void }) {
  const [page, setPage] = useState(1)
  const detail = useLoad<{ data: BillingDetail }>(`/reports/sellers/${sellerId}/documents?${query}&page=${page}`)
  const data = detail.data?.data

  return (
    <Modal title={`Facturación de ${name}`} onClose={onClose} wide>
      <FormError message={detail.error} />
      {data && (
        <p className="mb-3 text-sm text-ink-muted">
          {data.totals.documents.toLocaleString('es')} documentos · venta neta <span className="tabular font-medium text-ink">{formatMoney(data.totals.net_total)}</span> ·
          IVA <span className="tabular">{formatMoney(data.totals.tax_total)}</span> · total <span className="tabular">{formatMoney(data.totals.grand_total)}</span>
        </p>
      )}
      <DataTable label={`Documentos facturados por ${name}`}>
        <thead>
          <tr>
            <th className={th}>Documento</th>
            <th className={th}>Fecha</th>
            <th className={th}>Cliente</th>
            <th className={th}>Sucursal</th>
            <th className={th}>Facturó</th>
            <th className={`${th} text-right`}>Líneas</th>
            <th className={`${th} text-right`}>Venta neta</th>
            <th className={`${th} text-right`}>Total</th>
          </tr>
        </thead>
        <tbody>
          {detail.loading && !data && <TableSkeleton cols={8} />}
          {data && data.rows.length === 0 && <EmptyRow cols={8}>No hay documentos en el periodo.</EmptyRow>}
          {data?.rows.map((d) => (
            <tr key={d.id} className={tr}>
              <td className={`${td} font-medium`}>{d.document}</td>
              <td className={td}>{formatDate(d.issued_on)}</td>
              <td className={td}>{d.customer_name}<span className="block text-xs text-ink-muted">{d.customer_code}</span></td>
              <td className={td}>{d.branch}</td>
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
    </Modal>
  )
}
