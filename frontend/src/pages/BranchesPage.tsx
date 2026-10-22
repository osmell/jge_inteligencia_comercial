import { GitBranch, Pencil, Plus } from 'lucide-react'
import { useState, type FormEvent } from 'react'
import { useLoad } from '../lib/useLoad'
import { useFormValues } from '../lib/useFormValues'
import { useSaveForm } from '../lib/useSaveForm'
import type { Branch, Company } from '../lib/types'
import { useToast } from '../components/toast'
import {
  Alert, Button, CheckField, DataTable, EmptyRow, FormActions, FormError, Modal, PageHeader, SearchInput, SelectField, StatusBadge,
  TableSkeleton, TextField, td, th, tr,
} from '../components/ui'

type Relocation = { can_move: boolean; blockers: { label: string; count: number }[] }

function BranchForm({ branch, companies, onClose, onSaved }: { branch: Branch | null; companies: Company[]; onClose: () => void; onSaved: () => void }) {
  const { values: v, set } = useFormValues({ companyId: String(branch?.company_id ?? companies[0]?.id ?? ''), code: branch?.code ?? '', name: branch?.name ?? '', active: branch?.is_active ?? true })
  const { errors, message, saving, submit } = useSaveForm()
  // Al editar se averigua si la sucursal tiene datos relacionados: sin ellos se puede mover de compañía.
  const relocation = useLoad<{ data: Relocation }>(`/admin/branches/${branch?.id ?? 0}/relocation`, { enabled: !!branch })
  const reloc = relocation.data?.data
  const canMove = reloc?.can_move === true

  async function onSubmit(e: FormEvent) {
    e.preventDefault()
    const moved = branch !== null && v.companyId !== String(branch.company_id)
    const body = { code: v.code, name: v.name, is_active: v.active, ...(branch === null || moved ? { company_id: Number(v.companyId) } : {}) }
    if (await submit(branch ? 'PUT' : 'POST', branch ? `/admin/branches/${branch.id}` : '/admin/branches', body)) onSaved()
  }

  return (
    <Modal title={branch ? 'Editar sucursal' : 'Nueva sucursal'} onClose={onClose}>
      <form onSubmit={onSubmit} className="space-y-4" noValidate>
        <FormError message={message} />
        <SelectField
          id="branch-company"
          label="Compañía"
          value={v.companyId}
          onChange={(e) => set('companyId', e.target.value)}
          disabled={!!branch && !canMove}
          hint={branch && canMove ? 'Esta sucursal no tiene datos relacionados: puedes moverla a otra compañía.' : undefined}
          errors={errors.company_id}
        >
          {companies.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
        </SelectField>
        {branch && reloc && !canMove && (
          <Alert tone="warning">
            {reloc.blockers.length > 0
              ? <>No se puede mover a otra compañía porque tiene datos relacionados: {reloc.blockers.map((b) => `${b.count} ${b.label}`).join(', ')}. Para cambiarla de compañía, desactívala y crea una nueva en la otra.</>
              : 'No tienes acceso completo a la compañía actual de esta sucursal, por eso no puedes moverla.'}
          </Alert>
        )}
        <TextField id="branch-code" label="Código" value={v.code} onChange={(e) => set('code', e.target.value)} errors={errors.code} />
        <TextField id="branch-name" label="Nombre" value={v.name} onChange={(e) => set('name', e.target.value)} errors={errors.name} />
        <CheckField label="Activa" checked={v.active} onChange={(e) => set('active', e.target.checked)} />
        <FormActions saving={saving} onCancel={onClose} />
      </form>
    </Modal>
  )
}

export default function BranchesPage() {
  const toast = useToast()
  const branches = useLoad<{ data: Branch[] }>('/admin/branches')
  // Compañías visibles para el usuario (no requiere el permiso de Compañías).
  const companies = useLoad<{ data: Company[] }>('/companies')
  const [form, setForm] = useState<Branch | 'new' | null>(null)
  const [query, setQuery] = useState('')

  const companyList = companies.data?.data ?? []
  const companyName = (id: number) => companyList.find((c) => c.id === id)?.name ?? `#${id}`
  const q = query.trim().toLowerCase()
  const shown = (branches.data?.data ?? []).filter((b) => !q || [b.code, b.name, companyName(b.company_id)].some((v) => v.toLowerCase().includes(q)))

  return (
    <section>
      <PageHeader
        title="Sucursales"
        description="Catálogo de sucursales por compañía. No se eliminan: se desactivan."
        action={<Button icon={<Plus className="h-4 w-4" aria-hidden="true" />} onClick={() => setForm('new')} disabled={companyList.length === 0}>Nueva sucursal</Button>}
      />
      <div className="mb-3"><SearchInput label="Buscar sucursales" value={query} onChange={setQuery} /></div>
      <FormError message={branches.error} />
      <DataTable label="Sucursales">
        <thead><tr><th className={th}>Compañía</th><th className={th}>Código</th><th className={th}>Nombre</th><th className={th}>Estado</th><th className={th}><span className="sr-only">Acciones</span></th></tr></thead>
        <tbody>
          {branches.loading && !branches.data && <TableSkeleton cols={5} />}
          {shown.map((b) => (
            <tr key={b.id} className={tr}>
              <td className={td}>{companyName(b.company_id)}</td>
              <td className={`${td} font-mono text-[13px]`}>{b.code}</td>
              <td className={`${td} font-medium`}>{b.name}</td>
              <td className={td}><StatusBadge active={b.is_active} /></td>
              <td className={`${td} text-right`}>
                <Button variant="ghost" small icon={<Pencil className="h-4 w-4" aria-hidden="true" />} onClick={() => setForm(b)} aria-label={`Editar sucursal ${b.name}`}>Editar</Button>
              </td>
            </tr>
          ))}
          {!branches.loading && shown.length === 0 && (
            <EmptyRow cols={5}><GitBranch className="mx-auto mb-2 h-6 w-6 text-ink-subtle" aria-hidden="true" />{q ? 'Ninguna sucursal coincide con la búsqueda.' : 'No hay sucursales registradas.'}</EmptyRow>
          )}
        </tbody>
      </DataTable>

      {form && (
        <BranchForm
          branch={form === 'new' ? null : form}
          companies={companyList}
          onClose={() => setForm(null)}
          onSaved={() => { toast.success(form === 'new' ? 'Sucursal creada.' : 'Sucursal actualizada.'); setForm(null); void branches.reload() }}
        />
      )}
    </section>
  )
}
