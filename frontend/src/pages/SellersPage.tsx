import { useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { useToast } from '../components/toast'
import { Button, CheckField, DataTable, EmptyRow, FormError, PageHeader, SelectField, TableSkeleton, TextField, td, th, tr, Th } from '../components/ui'
import { ApiError, api } from '../lib/api'
import type { SellerRow } from '../lib/customers'
import type { Branch, Company } from '../lib/types'
import { useLoad } from '../lib/useLoad'

type Patch = { name?: string; is_active?: boolean; branch_id?: number | null; is_default?: boolean; supervisor_id?: number | null; user_id?: number | null }

export default function SellersPage() {
  const toast = useToast()
  const sellers = useLoad<{ data: SellerRow[] }>('/customers/sellers')
  const companies = useLoad<{ data: Company[] }>('/companies')
  const branches = useLoad<{ data: Branch[] }>('/branches')
  const supervisors = useLoad<{ data: { id: number; name: string }[] }>('/customers/supervisors')
  const users = useLoad<{ data: { id: number; name: string; username: string }[] }>('/customers/seller-users')
  const [error, setError] = useState<string | null>(null)
  const [newName, setNewName] = useState('')
  const [newCompany, setNewCompany] = useState('')
  const [editing, setEditing] = useState<{ id: number; name: string } | null>(null)
  const [busy, setBusy] = useState(false)
  const rows = sellers.data?.data ?? []
  const companyList = companies.data?.data ?? []
  const companyId = newCompany || (companyList.length === 1 ? String(companyList[0].id) : '')

  function message(e: unknown) {
    return e instanceof ApiError ? (Object.values(e.errors)[0]?.[0] ?? e.message) : 'No se pudo guardar el cambio.'
  }

  async function save(s: SellerRow, patch: Patch, done?: string): Promise<boolean> {
    setError(null)
    try {
      await api(`/customers/sellers/${s.id}`, { method: 'PUT', body: patch })
      toast.success(done ?? `Vendedor ${s.name} actualizado.`)
      sellers.reload()
      return true
    } catch (e) {
      setError(message(e))
      return false
    }
  }

  async function create(e: FormEvent) {
    e.preventDefault()
    setBusy(true)
    setError(null)
    try {
      await api('/customers/sellers', { method: 'POST', body: { company_id: Number(companyId), name: newName } })
      toast.success('Vendedor creado.')
      setNewName('')
      sellers.reload()
    } catch (err) {
      setError(message(err))
    } finally {
      setBusy(false)
    }
  }

  async function rename(e: FormEvent, s: SellerRow) {
    e.preventDefault()
    if (editing && (await save(s, { name: editing.name }, 'Vendedor renombrado.'))) setEditing(null)
  }

  const selectClass = 'w-full rounded-md border border-line bg-surface px-2 py-1.5 text-sm'

  return (
    <section>
      <PageHeader
        title="Vendedores"
        description="Mantenimiento del catálogo: altas, nombre, estado, sucursal, usuario vinculado y supervisor. Marca como «Tienda por defecto» al vendedor que recibe los clientes sin vendedor de una sucursal (solo uno por sucursal)."
        action={<Link className="text-sm text-jge-blue hover:underline" to="/clientes">← Volver a clientes</Link>}
      />

      <form onSubmit={create} className="mb-4 flex flex-wrap items-end gap-3">
        <SelectField id="seller-company" label="Compañía" value={companyId} onChange={(e) => setNewCompany(e.target.value)}>
          <option value="">Elige…</option>
          {companyList.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
        </SelectField>
        <div className="w-full sm:w-72"><TextField id="seller-new-name" label="Nuevo vendedor" value={newName} maxLength={255} onChange={(e) => setNewName(e.target.value)} /></div>
        <Button type="submit" loading={busy} disabled={newName.trim() === '' || companyId === ''}>Agregar vendedor</Button>
      </form>
      <p className="mb-3 text-sm text-ink-muted">
        Las ventas se enlazan al vendedor por su nombre al importar: si renombras a alguien, el archivo de ventas debe traer el nombre nuevo o se creará otro vendedor.
      </p>

      <FormError message={error ?? sellers.error} />
      <DataTable label="Vendedores">
        <thead>
          <tr>
            <th className={th}>Vendedor</th>
            <th className={th}>Sucursal</th>
            <Th info={{ text: 'Vendedor que recibe los clientes sin vendedor asignado de su sucursal. Solo puede haber uno por sucursal.' }}>Tienda por defecto</Th>
            <Th info={{ text: 'Persona que supervisa al vendedor. Un supervisor ve la información de su equipo.' }}>Supervisor</Th>
            <Th info={{ text: 'Usuario del sistema que corresponde al vendedor. Sus contactos, acciones y cotizaciones se atribuyen al vendedor.' }}>Usuario vinculado</Th>
            <th className={th}>Estado</th>
          </tr>
        </thead>
        <tbody>
          {sellers.loading && !sellers.data && <TableSkeleton cols={6} />}
          {!sellers.loading && rows.length === 0 && <EmptyRow cols={6}>Aún no hay vendedores. Se crean al importar ventas y con cada sucursal.</EmptyRow>}
          {rows.map((s) => (
            <tr key={s.id} className={tr}>
              <td className={`${td} font-medium`}>
                {editing?.id === s.id ? (
                  <form onSubmit={(e) => void rename(e, s)} className="flex items-center gap-2">
                    <input aria-label={`Nombre de ${s.name}`} className="w-full max-w-[12rem] rounded-md border border-line px-2 py-1" value={editing.name} maxLength={255} onChange={(e) => setEditing({ id: s.id, name: e.target.value })} />
                    <Button type="submit" small disabled={editing.name.trim() === ''}>Guardar</Button>
                    <Button type="button" small variant="secondary" onClick={() => setEditing(null)}>Cancelar</Button>
                  </form>
                ) : (
                  <>
                    {s.name}{' '}
                    <button type="button" className="text-xs font-normal text-jge-blue hover:underline" aria-label={`Renombrar ${s.name}`} onClick={() => setEditing({ id: s.id, name: s.name })}>Renombrar</button>
                  </>
                )}
              </td>
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
              <td className={td}>
                <select aria-label={`Usuario de ${s.name}`} className={selectClass} value={s.user_id ?? ''} onChange={(e) => void save(s, { user_id: e.target.value === '' ? null : Number(e.target.value) })}>
                  <option value="">Sin usuario</option>
                  {(users.data?.data ?? []).map((u) => <option key={u.id} value={u.id}>{u.name} ({u.username})</option>)}
                </select>
              </td>
              <td className={td}>
                <CheckField label="Activo" aria-label={`${s.name} está activo`} checked={s.is_active} onChange={(e) => void save(s, { is_active: e.target.checked })} />
              </td>
            </tr>
          ))}
        </tbody>
      </DataTable>
    </section>
  )
}
