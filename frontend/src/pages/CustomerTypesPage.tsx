import { useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { useToast } from '../components/toast'
import { Button, DataTable, EmptyRow, FormError, PageHeader, TableSkeleton, TextField, td, th, tr } from '../components/ui'
import { ApiError, api } from '../lib/api'
import type { CustomerType } from '../lib/customers'
import { useLoad } from '../lib/useLoad'

export default function CustomerTypesPage() {
  const toast = useToast()
  const list = useLoad<{ data: CustomerType[] }>('/customers/types')
  const [name, setName] = useState('')
  const [editing, setEditing] = useState<{ id: number; name: string } | null>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const types = list.data?.data ?? []

  async function send(path: string, method: 'POST' | 'PUT', body: unknown, done: string) {
    setBusy(true)
    setError(null)
    try {
      await api(path, { method, body })
      toast.success(done)
      list.reload()
      return true
    } catch (e) {
      setError(e instanceof ApiError ? (e.errors.name?.[0] ?? e.message) : 'No se pudo guardar.')
      return false
    } finally {
      setBusy(false)
    }
  }

  async function create(e: FormEvent) {
    e.preventDefault()
    if (await send('/customers/types', 'POST', { name }, 'Tipo creado.')) setName('')
  }

  async function rename(e: FormEvent) {
    e.preventDefault()
    if (editing && (await send(`/customers/types/${editing.id}`, 'PUT', { name: editing.name }, 'Tipo actualizado.'))) setEditing(null)
  }

  return (
    <section>
      <PageHeader
        title="Tipos de cliente"
        description="Catálogo permanente: los tipos no se borran, se desactivan para que dejen de ofrecerse."
        action={<Link className="text-sm text-jge-blue hover:underline" to="/clientes">← Volver a clientes</Link>}
      />

      <form onSubmit={create} className="mb-4 flex flex-wrap items-end gap-3">
        <div className="w-full sm:w-72">
          <TextField id="type-name" label="Nuevo tipo" value={name} maxLength={100} onChange={(e) => setName(e.target.value)} />
        </div>
        <Button type="submit" loading={busy && !editing} disabled={name.trim() === ''}>Agregar tipo</Button>
      </form>

      <FormError message={error ?? list.error} />
      <DataTable label="Tipos de cliente">
        <thead><tr><th className={th}>Nombre</th><th className={th}>Estado</th><th className={`${th} text-right`}>Acciones</th></tr></thead>
        <tbody>
          {list.loading && !list.data && <TableSkeleton cols={3} />}
          {!list.loading && types.length === 0 && <EmptyRow cols={3}>Aún no hay tipos de cliente. Crea el primero arriba.</EmptyRow>}
          {types.map((t) => (
            <tr key={t.id} className={tr}>
              <td className={`${td} font-medium`}>
                {editing?.id === t.id ? (
                  <form onSubmit={rename} className="flex items-center gap-2">
                    <input aria-label={`Nombre de ${t.name}`} className="w-full max-w-xs rounded-md border border-line px-2 py-1" value={editing.name} maxLength={100} onChange={(e) => setEditing({ id: t.id, name: e.target.value })} />
                    <Button type="submit" small loading={busy} disabled={editing.name.trim() === ''}>Guardar</Button>
                    <Button type="button" small variant="secondary" onClick={() => setEditing(null)}>Cancelar</Button>
                  </form>
                ) : t.name}
              </td>
              <td className={td}>{t.active ? 'Activo' : 'Inactivo'}</td>
              <td className={`${td} text-right`}>
                <div className="flex justify-end gap-2">
                  <Button small variant="secondary" aria-label={`Renombrar ${t.name}`} onClick={() => setEditing({ id: t.id, name: t.name })}>Renombrar</Button>
                  <Button small variant="secondary" aria-label={`${t.active ? 'Desactivar' : 'Activar'} ${t.name}`} onClick={() => void send(`/customers/types/${t.id}`, 'PUT', { active: !t.active }, t.active ? 'Tipo desactivado.' : 'Tipo activado.')}>
                    {t.active ? 'Desactivar' : 'Activar'}
                  </Button>
                </div>
              </td>
            </tr>
          ))}
        </tbody>
      </DataTable>
    </section>
  )
}
