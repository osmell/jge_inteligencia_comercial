import { useState } from 'react'
import { formatDate } from '../../lib/customers'
import { INTEGRITY_LABEL, type ConciliationIssues } from '../../lib/reports'
import { useLoad } from '../../lib/useLoad'
import { Button, DataTable, EmptyRow, FormError, Modal, TableSkeleton, td, th, tr, Th } from '../ui'

/** Documentos (o facturas de crédito) que tienen una diferencia de conciliación concreta, con la descripción del problema. */
export default function IssuesModal({ code, query, onClose }: { code: string; query: string; onClose: () => void }) {
  const [page, setPage] = useState(1)
  const load = useLoad<{ data: ConciliationIssues }>(`/reports/conciliation/issues?${query}&code=${code}&page=${page}`)
  const data = load.data?.data

  return (
    <Modal title={INTEGRITY_LABEL[code] ?? code} onClose={onClose} wide>
      <FormError message={load.error} />
      {data && <p className="mb-3 text-sm text-ink-muted">{data.total.toLocaleString('es')} con diferencia.</p>}
      <DataTable label={`Diferencias: ${INTEGRITY_LABEL[code] ?? code}`}>
        <thead>
          <tr>
            <th className={th}>Documento</th>
            <th className={th}>Fecha</th>
            <th className={th}>Cliente</th>
            <th className={th}>Sucursal</th>
            <Th info={{ text: 'Descripción de la diferencia encontrada en el documento.' }}>Qué pasa</Th>
          </tr>
        </thead>
        <tbody>
          {load.loading && !data && <TableSkeleton cols={5} />}
          {data && data.rows.length === 0 && <EmptyRow cols={5}>No hay diferencias.</EmptyRow>}
          {data?.rows.map((r) => (
            <tr key={r.id} className={tr}>
              <td className={`${td} font-medium`}>{r.document}</td>
              <td className={td}>{formatDate(r.issued_on)}</td>
              <td className={td}>{r.customer_name}</td>
              <td className={td}>{r.branch}</td>
              <td className={td}>{r.detail}</td>
            </tr>
          ))}
        </tbody>
      </DataTable>
      {data && data.last_page > 1 && (
        <div className="mt-3 flex items-center justify-between text-sm text-ink-muted">
          <span>Página {data.page} de {data.last_page}</span>
          <div className="flex gap-2">
            <Button variant="secondary" disabled={page === 1 || load.loading} onClick={() => setPage((p) => p - 1)}>Anterior</Button>
            <Button variant="secondary" disabled={page >= data.last_page || load.loading} onClick={() => setPage((p) => p + 1)}>Siguiente</Button>
          </div>
        </div>
      )}
    </Modal>
  )
}
