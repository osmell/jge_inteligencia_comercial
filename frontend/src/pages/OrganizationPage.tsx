import { useState, type FormEvent } from 'react'
import { useLoad } from '../lib/useLoad'
import { submitForm } from '../lib/saveForm'
import type { Branch, Company } from '../lib/types'
import {
  CheckField, DataTable, FormActions, FormError, Modal, PageHeader, PrimaryButton, SelectField, StatusBadge, TextField, td, th,
  type FieldErrors,
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
      <form onSubmit={onSubmit} className="space-y-3" noValidate>
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
      <form onSubmit={onSubmit} className="space-y-3" noValidate>
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

export default function OrganizationPage() {
  const companies = useLoad<{ data: Company[] }>('/admin/companies')
  const branches = useLoad<{ data: Branch[] }>('/admin/branches')
  const [companyForm, setCompanyForm] = useState<Company | 'new' | null>(null)
  const [branchForm, setBranchForm] = useState<Branch | 'new' | null>(null)

  const companyList = companies.data?.data ?? []
  const companyName = (id: number) => companyList.find((c) => c.id === id)?.name ?? `#${id}`

  return (
    <div className="space-y-8">
      <h1 className="sr-only">Compañías y sucursales</h1>
      <section>
        <PageHeader level={2} title="Compañías" action={<PrimaryButton onClick={() => setCompanyForm('new')}>Nueva compañía</PrimaryButton>} />
        <FormError message={companies.error} />
        <DataTable label="Compañías">
          <thead><tr><th className={th}>Código</th><th className={th}>Nombre</th><th className={th}>Estado</th><th className={th}><span className="sr-only">Acciones</span></th></tr></thead>
          <tbody>
            {companyList.map((c) => (
              <tr key={c.id}>
                <td className={td}>{c.code}</td>
                <td className={td}>{c.name}</td>
                <td className={td}><StatusBadge active={c.is_active} /></td>
                <td className={`${td} text-right`}><button className="text-jge-blue underline" onClick={() => setCompanyForm(c)} aria-label={`Editar compañía ${c.name}`}>Editar</button></td>
              </tr>
            ))}
            {!companies.loading && companyList.length === 0 && <tr><td className={td} colSpan={4}>No hay compañías registradas.</td></tr>}
          </tbody>
        </DataTable>
      </section>

      <section>
        <PageHeader
          level={2}
          title="Sucursales"
          action={<PrimaryButton onClick={() => setBranchForm('new')} disabled={companyList.length === 0}>Nueva sucursal</PrimaryButton>}
        />
        <FormError message={branches.error} />
        <DataTable label="Sucursales">
          <thead><tr><th className={th}>Compañía</th><th className={th}>Código</th><th className={th}>Nombre</th><th className={th}>Estado</th><th className={th}><span className="sr-only">Acciones</span></th></tr></thead>
          <tbody>
            {(branches.data?.data ?? []).map((b) => (
              <tr key={b.id}>
                <td className={td}>{companyName(b.company_id)}</td>
                <td className={td}>{b.code}</td>
                <td className={td}>{b.name}</td>
                <td className={td}><StatusBadge active={b.is_active} /></td>
                <td className={`${td} text-right`}><button className="text-jge-blue underline" onClick={() => setBranchForm(b)} aria-label={`Editar sucursal ${b.name}`}>Editar</button></td>
              </tr>
            ))}
            {!branches.loading && (branches.data?.data.length ?? 0) === 0 && <tr><td className={td} colSpan={5}>No hay sucursales registradas.</td></tr>}
          </tbody>
        </DataTable>
      </section>

      {companyForm && (
        <CompanyForm
          company={companyForm === 'new' ? null : companyForm}
          onClose={() => setCompanyForm(null)}
          onSaved={() => { setCompanyForm(null); void companies.reload() }}
        />
      )}
      {branchForm && (
        <BranchForm
          branch={branchForm === 'new' ? null : branchForm}
          companies={companyList}
          onClose={() => setBranchForm(null)}
          onSaved={() => { setBranchForm(null); void branches.reload() }}
        />
      )}
    </div>
  )
}
