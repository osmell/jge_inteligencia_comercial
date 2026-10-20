import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useToast } from '../components/toast'
import { CheckField, DataTable, EmptyRow, FormError, PageHeader, TableSkeleton, td, th, tr } from '../components/ui'
import { api } from '../lib/api'
import type { SellerRow } from '../lib/customers'
import type { Branch } from '../lib/types'
import { useLoad } from '../lib/useLoad'

export default function SellersPage() {
  const toast = useToast()
  const sellers = useLoad<{ data: SellerRow[] }>('/customers/sellers')
  const branches = useLoad<{ data: Branch[] }>('/branches')
  const supervisors = useLoad<{ data: { id: number; name: string }[] }>('/customers/supervisors')
  const [error, setError] = useState<string | null>(null)
  const rows = sellers.data?.data ?? []

  async function save(s: SellerRow, patch: { branch_id?: number | null; is_default?: boolean; supervisor_id?: number | null }) {
    setError(null)
    try {
      await api(`/customers/sellers/${s.id}`, { method: 'PUT', body: patch })
      toast.success(`Vendedor ${s.name} actualizado.`)
      sellers.reload()
    } catch (e) {
      setError(e instanceof Error ? e.message : 'No se pudo guardar el cambio.')
    }
  }

  const selectClass = 'w-full rounded-md border border-line bg-surface px-2 py-1.5 text-sm'

  return (
    <section>
      <PageHeader
        title="Vendedores"
        description="Marca como «Tienda por defecto» al vendedor que recibe los clientes sin vendedor de una sucursal (solo uno por sucursal) y define quién supervisa a cada vendedor."
        action={<Link className="text-sm text-jge-blue hover:underline" to="/clientes">← Volver a clientes</Link>}
      />
      <FormError message={error ?? sellers.error} />
      <DataTable label="Vendedores">
        <thead>
          <tr>
            <th className={th}>Vendedor</th>
            <th className={th}>Sucursal</th>
            <th className={th}>Tienda por defecto</th>
            <th className={th}>Supervisor</th>
          </tr>
        </thead>
        <tbody>
          {sellers.loading && !sellers.data && <TableSkeleton cols={4} />}
          {!sellers.loading && rows.length === 0 && <EmptyRow cols={4}>Aún no hay vendedores. Se crean al importar ventas y con cada sucursal.</EmptyRow>}
          {rows.map((s) => (
            <tr key={s.id} className={tr}>
              <td className={`${td} font-medium`}>{s.name}</td>
              <td className={td}>
                <select aria-label={`Sucursal de ${s.name}`} className={selectClass} value={s.branch_id ?? ''} onChange={(e) => void save(s, { branch_id: e.target.value === '' ? null : Number(e.target.value) })}>
                  <option value="">Sin sucursal</option>
                  {(branches.data?.data ?? []).filter((b) => b.company_id === s.company_id).map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
                </select>
              </td>
              <td className={td}>
                <CheckField label="Por defecto" aria-label={`${s.name} es tienda por defecto`} checked={s.is_default} disabled={!s.branch_id} onChange={(e) => void save(s, { is_default: e.target.checked })} />
              </td>
              <td className={td}>
                <select aria-label={`Supervisor de ${s.name}`} className={selectClass} value={s.supervisor?.id ?? ''} onChange={(e) => void save(s, { supervisor_id: e.target.value === '' ? null : Number(e.target.value) })}>
                  <option value="">Sin supervisor</option>
                  {(supervisors.data?.data ?? []).map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
                </select>
              </td>
            </tr>
          ))}
        </tbody>
      </DataTable>
    </section>
  )
}
