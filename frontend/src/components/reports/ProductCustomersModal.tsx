import { useState } from 'react'
import { formatDate } from '../../lib/customers'
import { formatMoney } from '../../lib/format'
import { MIX_LEVEL_LABEL, type MixLevel, type ProductCustomers } from '../../lib/reports'
import { useLoad } from '../../lib/useLoad'
import { usePaged } from '../../lib/usePaged'
import { DataTable, EmptyRow, FormError, Modal, Pager, TableSkeleton, td, th, tr, Th } from '../ui'

/** A quién se acota la lista: a todos (el equipo) o a lo que compró el cliente de un vendedor u operador. */
export type CustomersScope = { seller_id: number } | { operator_id: number } | null

export type CustomersTarget = { level: MixLevel; itemKey: string; name: string; scope?: CustomersScope }

const n = (v: number) => v.toLocaleString('en-US')

/** El número de clientes de una fila: abre la lista de quiénes son. Sin clientes es solo texto. */
export function CustomersCount({ value, label, onOpen }: { value: number; label: string; onOpen: () => void }) {
  if (value === 0) return <span className="tabular">0</span>
  return (
    <button type="button" onClick={onOpen} aria-label={`Ver los ${n(value)} clientes de ${label}`} className="tabular cursor-pointer font-medium text-jge-blue underline-offset-2 hover:underline">
      {n(value)}
    </button>
  )
}

/** Clientes que compraron un producto, categoría, línea o grupo en el periodo del reporte. `query` es el periodo y los filtros del reporte. */
export default function ProductCustomersModal({ target, query, onClose }: { target: CustomersTarget; query: string; onClose: () => void }) {
  const scope = target.scope ? `&${Object.entries(target.scope).map(([k, v]) => `${k}=${v}`).join('&')}` : ''
  const load = useLoad<{ data: ProductCustomers }>(`/reports/products/customers?${query}&level=${target.level}&key=${encodeURIComponent(target.itemKey)}${scope}`)
  const data = load.data?.data
  const [filter, setFilter] = useState('')
  const needle = filter.trim().toLowerCase()
  const rows = (data?.rows ?? []).filter((r) => !needle || r.name.toLowerCase().includes(needle) || r.code.toLowerCase().includes(needle))
  const paged = usePaged(rows)

  return (
    <Modal title={`Clientes de ${target.name}`} onClose={onClose} extraWide>
      <FormError message={load.error} />
      <p className="mb-3 text-sm text-ink-muted">
        {MIX_LEVEL_LABEL[target.level]}: <span className="font-medium text-ink">{target.name}</span>
        {data && <> · {n(data.totals.customers)} clientes · venta neta <span className="tabular font-medium text-ink">{formatMoney(data.totals.net_total)}</span></>}
      </p>
      {data && data.rows.length > 10 && (
        <input
          type="search"
          value={filter}
          onChange={(e) => setFilter(e.target.value)}
          aria-label="Buscar cliente"
          placeholder="Buscar por nombre o código"
          className="mb-3 min-h-11 w-full max-w-sm rounded-lg border border-line-strong bg-surface px-3 text-sm text-ink placeholder:text-ink-subtle"
        />
      )}
      <DataTable label={`Clientes de ${target.name}`}>
        <thead>
          <tr>
            <th className={th}>Código</th>
            <th className={th}>Cliente</th>
            <Th right info="sales_documents">Documentos</Th>
            <Th right info="sales_units">Unidades</Th>
            <Th right info="sales_net">Venta neta</Th>
            <th className={th}>Última compra</th>
          </tr>
        </thead>
        <tbody>
          {load.loading && !data && <TableSkeleton cols={6} />}
          {data && rows.length === 0 && <EmptyRow cols={6}>{needle ? 'Ningún cliente coincide con la búsqueda.' : 'No hay clientes con compras en el periodo.'}</EmptyRow>}
          {paged.rows.map((c) => (
            <tr key={c.code} className={tr}>
              <td className={`${td} tabular text-ink-muted`}>{c.code}</td>
              <td className={`${td} font-medium`}>{c.name}</td>
              <td className={`${td} tabular text-right`}>{n(c.documents)}</td>
              <td className={`${td} tabular text-right`}>{n(Math.round(c.units))}</td>
              <td className={`${td} tabular text-right font-medium`}>{formatMoney(c.net_total)}</td>
              <td className={`${td} whitespace-nowrap`}>{formatDate(c.last_purchase)}</td>
            </tr>
          ))}
        </tbody>
      </DataTable>
      <Pager paged={paged} />
    </Modal>
  )
}
