import { useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { useToast } from '../components/toast'
import { Button, CheckField, DataTable, EmptyRow, FormError, PageHeader, SelectField, TableSkeleton, TextField, td, th, tr, Th } from '../components/ui'
import { ApiError, api } from '../lib/api'
import type { OperatorRow } from '../lib/customers'
import type { Company } from '../lib/types'
import { useLoad } from '../lib/useLoad'

export default function OperatorsPage() {
  const toast = useToast()
  const operators = useLoad<{ data: OperatorRow[] }>('/customers/operators')
  const companies = useLoad<{ data: Company[] }>('/companies')
  const [error, setError] = useState<string | null>(null)
  const [newName, setNewName] = useState('')
  const [newCompany, setNewCompany] = useState('')
  const [editing, setEditing] = useState<{ id: number; name: string } | null>(null)
  const [busy, setBusy] = useState(false)
  const rows = operators.data?.data ?? []
  const companyList = companies.data?.data ?? []
  const companyId = newCompany || (companyList.length === 1 ? String(companyList[0].id) : '')
  const companyName = (id: number) => companyList.find((c) => c.id === id)?.name ?? ''

  async function send(path: string, method: 'POST' | 'PUT', body: unknown, done: string): Promise<boolean> {
    setBusy(true)
    setError(null)
    try {
      await api(path, { method, body })
      toast.success(done)
      operators.reload()
      return true
    } catch (e) {
      setError(e instanceof ApiError ? (Object.values(e.errors)[0]?.[0] ?? e.message) : 'No se pudo guardar el cambio.')
      return false
    } finally {
      setBusy(false)
    }
  }

  async function create(e: FormEvent) {
    e.preventDefault()
    if (await send('/customers/operators', 'POST', { company_id: Number(companyId), name: newName }, 'Facturador creado.')) setNewName('')
  }

  async function rename(e: FormEvent) {
    e.preventDefault()
    if (editing && (await send(`/customers/operators/${editing.id}`, 'PUT', { name: editing.name }, 'Facturador renombrado.'))) setEditing(null)
  }

  return (
    <section>
      <PageHeader
        title="Facturadores"
        description="Mantenimiento del catálogo de facturadores (operadores de las facturas): altas, nombre y estado. Un facturador inactivo ya no se ofrece al asignar clientes."
        action={<Link className="text-sm text-jge-blue hover:underline" to="/clientes">← Volver a clientes</Link>}
      />

      <form onSubmit={create} className="mb-4 flex flex-wrap items-end gap-3">
        <SelectField id="operator-company" label="Compañía" value={companyId} onChange={(e) => setNewCompany(e.target.value)}>
          <option value="">Elige…</option>
          {companyList.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
        </SelectField>
        <div className="w-full sm:w-72"><TextField id="operator-new-name" label="Nuevo facturador" value={newName} maxLength={255} onChange={(e) => setNewName(e.target.value)} /></div>
        <Button type="submit" loading={busy && !editing} disabled={newName.trim() === '' || companyId === ''}>Agregar facturador</Button>
      </form>
      <p className="mb-3 text-sm text-ink-muted">
        Las ventas se enlazan al facturador por su nombre al importar: si renombras a alguien, el archivo de ventas debe traer el nombre nuevo o se creará otro.
      </p>

      <FormError message={error ?? operators.error} />
      <DataTable label="Facturadores">
        <thead><tr><Th info={{ text: 'Persona que factura los documentos, tomada del archivo de ventas.' }}>Facturador</Th><th className={th}>Compañía</th><th className={th}>Estado</th></tr></thead>
        <tbody>
          {operators.loading && !operators.data && <TableSkeleton cols={3} />}
          {!operators.loading && rows.length === 0 && <EmptyRow cols={3}>Aún no hay facturadores. Se crean al importar ventas.</EmptyRow>}
          {rows.map((o) => (
            <tr key={o.id} className={tr}>
              <td className={`${td} font-medium`}>
                {editing?.id === o.id ? (
                  <form onSubmit={rename} className="flex items-center gap-2">
                    <input aria-label={`Nombre de ${o.name}`} className="w-full max-w-[12rem] rounded-md border border-line px-2 py-1" value={editing.name} maxLength={255} onChange={(e) => setEditing({ id: o.id, name: e.target.value })} />
                    <Button type="submit" small loading={busy} disabled={editing.name.trim() === ''}>Guardar</Button>
                    <Button type="button" small variant="secondary" onClick={() => setEditing(null)}>Cancelar</Button>
                  </form>
                ) : (
                  <>
                    {o.name}{' '}
                    <button type="button" className="text-xs font-normal text-jge-blue hover:underline" aria-label={`Renombrar ${o.name}`} onClick={() => setEditing({ id: o.id, name: o.name })}>Renombrar</button>
                  </>
                )}
              </td>
              <td className={td}>{companyName(o.company_id)}</td>
              <td className={td}>
                <CheckField label="Activo" aria-label={`${o.name} está activo`} checked={o.is_active} onChange={(e) => void send(`/customers/operators/${o.id}`, 'PUT', { is_active: e.target.checked }, 'Facturador actualizado.')} />
              </td>
            </tr>
          ))}
        </tbody>
      </DataTable>
    </section>
  )
}
