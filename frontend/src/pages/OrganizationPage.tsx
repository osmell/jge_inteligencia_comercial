import { Building2, GitBranch, Pencil, Plus } from 'lucide-react'
import { useState, type FormEvent } from 'react'
import { useLoad } from '../lib/useLoad'
import { submitForm } from '../lib/saveForm'
import type { Branch, Company } from '../lib/types'
import { useToast } from '../components/toast'
import {
  Button, CheckField, DataTable, EmptyRow, FormActions, FormError, Modal, PageHeader, SearchInput, SelectField, StatusBadge,
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

function BranchForm({ branch, companies, onClose, onSaved }: { branch: Branch | null; companies: Company[]; onClose: () => void; onSaved: () => void }) {
  const [companyId, setCompanyId] = useState(String(branch?.company_id ?? companies[0]?.id ?? ''))
  const [code, setCode] = useState(branch?.code ?? '')
  const [name, setName] = useState(branch?.name ?? '')
  const [active, setActive] = useState(branch?.is_active ?? true)
  const [errors, setErrors] = useState<FieldErrors>({})
  const [message, setMessage] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)

  async function onSubmit(e: FormEvent) {
    e.preventDefault()
    setSaving(true)
    const body = { code, name, is_active: active, ...(branch ? {} : { company_id: Number(companyId) }) }
    const r = await submitForm(branch ? 'PUT' : 'POST', branch ? `/admin/branches/${branch.id}` : '/admin/branches', body)
    setSaving(false)
    if (r.ok) return onSaved()
    setErrors(r.errors)
    setMessage(r.message)
  }

  return (
    <Modal title={branch ? 'Editar sucursal' : 'Nueva sucursal'} onClose={onClose}>
      <form onSubmit={onSubmit} className="space-y-4" noValidate>
        <FormError message={message} />
        <SelectField
          id="branch-company"
          label="Compañía"
          value={companyId}
          onChange={(e) => setCompanyId(e.target.value)}
          disabled={!!branch}
          hint={branch ? 'La compañía de una sucursal no se puede cambiar.' : undefined}
          errors={errors.company_id}
        >
          {companies.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
        </SelectField>
        <TextField id="branch-code" label="Código" value={code} onChange={(e) => setCode(e.target.value)} errors={errors.code} />
        <TextField id="branch-name" label="Nombre" value={name} onChange={(e) => setName(e.target.value)} errors={errors.name} />
        <CheckField label="Activa" checked={active} onChange={(e) => setActive(e.target.checked)} />
        <FormActions saving={saving} onCancel={onClose} />
      </form>
    </Modal>
  )
}

const matches = (query: string, ...values: string[]) => {
  const q = query.trim().toLowerCase()
  return !q || values.some((v) => v.toLowerCase().includes(q))
}

export default function OrganizationPage() {
  const toast = useToast()
  const companies = useLoad<{ data: Company[] }>('/admin/companies')
  const branches = useLoad<{ data: Branch[] }>('/admin/branches')
  const [companyForm, setCompanyForm] = useState<Company | 'new' | null>(null)
  const [branchForm, setBranchForm] = useState<Branch | 'new' | null>(null)
  const [query, setQuery] = useState('')

  const companyList = companies.data?.data ?? []
  const companyName = (id: number) => companyList.find((c) => c.id === id)?.name ?? `#${id}`
  const shownCompanies = companyList.filter((c) => matches(query, c.code, c.name))
  const shownBranches = (branches.data?.data ?? []).filter((b) => matches(query, b.code, b.name, companyName(b.company_id)))

  return (
    <div className="space-y-10">
      <header className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight">Compañías y sucursales</h1>
          <p className="mt-0.5 text-sm text-ink-muted">Estructura del grupo. Las entidades no se eliminan: se desactivan.</p>
        </div>
        <SearchInput label="Buscar compañías y sucursales" value={query} onChange={setQuery} />
      </header>

      <section>
        <PageHeader level={2} title="Compañías" action={<Button icon={<Plus className="h-4 w-4" aria-hidden="true" />} onClick={() => setCompanyForm('new')}>Nueva compañía</Button>} />
        <FormError message={companies.error} />
        <DataTable label="Compañías">
          <thead><tr><th className={th}>Código</th><th className={th}>Nombre</th><th className={th}>Estado</th><th className={th}><span className="sr-only">Acciones</span></th></tr></thead>
          <tbody>
            {companies.loading && !companies.data && <TableSkeleton cols={4} />}
            {shownCompanies.map((c) => (
              <tr key={c.id} className={tr}>
                <td className={`${td} font-mono text-[13px]`}>{c.code}</td>
                <td className={`${td} font-medium`}>{c.name}</td>
                <td className={td}><StatusBadge active={c.is_active} /></td>
                <td className={`${td} text-right`}>
                  <Button variant="ghost" small icon={<Pencil className="h-4 w-4" aria-hidden="true" />} onClick={() => setCompanyForm(c)} aria-label={`Editar compañía ${c.name}`}>Editar</Button>
                </td>
              </tr>
            ))}
            {!companies.loading && shownCompanies.length === 0 && (
              <EmptyRow cols={4}><Building2 className="mx-auto mb-2 h-6 w-6 text-ink-subtle" aria-hidden="true" />{query ? 'Ninguna compañía coincide con la búsqueda.' : 'No hay compañías registradas.'}</EmptyRow>
            )}
          </tbody>
        </DataTable>
      </section>

      <section>
        <PageHeader
          level={2}
          title="Sucursales"
          action={<Button icon={<Plus className="h-4 w-4" aria-hidden="true" />} onClick={() => setBranchForm('new')} disabled={companyList.length === 0}>Nueva sucursal</Button>}
        />
        <FormError message={branches.error} />
        <DataTable label="Sucursales">
          <thead><tr><th className={th}>Compañía</th><th className={th}>Código</th><th className={th}>Nombre</th><th className={th}>Estado</th><th className={th}><span className="sr-only">Acciones</span></th></tr></thead>
          <tbody>
            {branches.loading && !branches.data && <TableSkeleton cols={5} />}
            {shownBranches.map((b) => (
              <tr key={b.id} className={tr}>
                <td className={td}>{companyName(b.company_id)}</td>
                <td className={`${td} font-mono text-[13px]`}>{b.code}</td>
                <td className={`${td} font-medium`}>{b.name}</td>
                <td className={td}><StatusBadge active={b.is_active} /></td>
                <td className={`${td} text-right`}>
                  <Button variant="ghost" small icon={<Pencil className="h-4 w-4" aria-hidden="true" />} onClick={() => setBranchForm(b)} aria-label={`Editar sucursal ${b.name}`}>Editar</Button>
                </td>
              </tr>
            ))}
            {!branches.loading && shownBranches.length === 0 && (
              <EmptyRow cols={5}><GitBranch className="mx-auto mb-2 h-6 w-6 text-ink-subtle" aria-hidden="true" />{query ? 'Ninguna sucursal coincide con la búsqueda.' : 'No hay sucursales registradas.'}</EmptyRow>
            )}
          </tbody>
        </DataTable>
      </section>

      {companyForm && (
        <CompanyForm
          company={companyForm === 'new' ? null : companyForm}
          onClose={() => setCompanyForm(null)}
          onSaved={() => { toast.success(companyForm === 'new' ? 'Compañía creada.' : 'Compañía actualizada.'); setCompanyForm(null); void companies.reload() }}
        />
      )}
      {branchForm && (
        <BranchForm
          branch={branchForm === 'new' ? null : branchForm}
          companies={companyList}
          onClose={() => setBranchForm(null)}
          onSaved={() => { toast.success(branchForm === 'new' ? 'Sucursal creada.' : 'Sucursal actualizada.'); setBranchForm(null); void branches.reload() }}
        />
      )}
    </div>
  )
}
