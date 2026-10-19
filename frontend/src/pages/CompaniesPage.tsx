import { Building2, Pencil, Plus } from 'lucide-react'
import { useState, type FormEvent } from 'react'
import { useAuth } from '../auth/AuthContext'
import { useLoad } from '../lib/useLoad'
import { submitForm } from '../lib/saveForm'
import type { Company } from '../lib/types'
import { useToast } from '../components/toast'
import {
  Button, CheckField, DataTable, EmptyRow, FormActions, FormError, Modal, PageHeader, SearchInput, StatusBadge,
  TableSkeleton, TextField, td, th, tr, type FieldErrors,
} from '../components/ui'

function CompanyForm({ company, onClose, onSaved }: { company: Company | null; onClose: () => void; onSaved: () => void }) {
  const [code, setCode] = useState(company?.code ?? '')
  const [name, setName] = useState(company?.name ?? '')
  const [active, setActive] = useState(company?.is_active ?? true)
  const [errors, setErrors] = useState<FieldErrors>({})
  const [message, setMessage] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)

  async function onSubmit(e: FormEvent) {
    e.preventDefault()
    setSaving(true)
    const r = await submitForm(company ? 'PUT' : 'POST', company ? `/admin/companies/${company.id}` : '/admin/companies', { code, name, is_active: active })
    setSaving(false)
    if (r.ok) return onSaved()
    setErrors(r.errors)
    setMessage(r.message)
  }

  return (
    <Modal title={company ? 'Editar compañía' : 'Nueva compañía'} onClose={onClose}>
      <form onSubmit={onSubmit} className="space-y-4" noValidate>
        <FormError message={message} />
        <TextField id="company-code" label="Código" value={code} onChange={(e) => setCode(e.target.value)} errors={errors.code} />
        <TextField id="company-name" label="Nombre" value={name} onChange={(e) => setName(e.target.value)} errors={errors.name} />
        <CheckField label="Activa" checked={active} onChange={(e) => setActive(e.target.checked)} />
        <FormActions saving={saving} onCancel={onClose} />
      </form>
    </Modal>
  )
}

export default function CompaniesPage() {
  const toast = useToast()
  const { user } = useAuth()
  const companies = useLoad<{ data: Company[] }>('/admin/companies')
  const [form, setForm] = useState<Company | 'new' | null>(null)
  const [query, setQuery] = useState('')

  // Solo el Administrador crea compañías; el resto edita las de su ámbito.
  const canCreate = user?.role.slug === 'administrador'
  const q = query.trim().toLowerCase()
  const shown = (companies.data?.data ?? []).filter((c) => !q || [c.code, c.name].some((v) => v.toLowerCase().includes(q)))

  return (
    <section>
      <PageHeader
        title="Compañías"
        description="Catálogo de compañías del grupo. No se eliminan: se desactivan."
        action={canCreate && <Button icon={<Plus className="h-4 w-4" aria-hidden="true" />} onClick={() => setForm('new')}>Nueva compañía</Button>}
      />
      <div className="mb-3"><SearchInput label="Buscar compañías" value={query} onChange={setQuery} /></div>
      <FormError message={companies.error} />
      <DataTable label="Compañías">
        <thead><tr><th className={th}>Código</th><th className={th}>Nombre</th><th className={th}>Estado</th><th className={th}><span className="sr-only">Acciones</span></th></tr></thead>
        <tbody>
          {companies.loading && !companies.data && <TableSkeleton cols={4} />}
          {shown.map((c) => (
            <tr key={c.id} className={tr}>
              <td className={`${td} font-mono text-[13px]`}>{c.code}</td>
              <td className={`${td} font-medium`}>{c.name}</td>
              <td className={td}><StatusBadge active={c.is_active} /></td>
              <td className={`${td} text-right`}>
                <Button variant="ghost" small icon={<Pencil className="h-4 w-4" aria-hidden="true" />} onClick={() => setForm(c)} aria-label={`Editar compañía ${c.name}`}>Editar</Button>
              </td>
            </tr>
          ))}
          {!companies.loading && shown.length === 0 && (
            <EmptyRow cols={4}><Building2 className="mx-auto mb-2 h-6 w-6 text-ink-subtle" aria-hidden="true" />{q ? 'Ninguna compañía coincide con la búsqueda.' : 'No hay compañías registradas.'}</EmptyRow>
          )}
        </tbody>
      </DataTable>

      {form && (
        <CompanyForm
          company={form === 'new' ? null : form}
          onClose={() => setForm(null)}
          onSaved={() => { toast.success(form === 'new' ? 'Compañía creada.' : 'Compañía actualizada.'); setForm(null); void companies.reload() }}
        />
      )}
    </section>
  )
}
