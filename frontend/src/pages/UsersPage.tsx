import { useState, type FormEvent } from 'react'
import { useAuth } from '../auth/AuthContext'
import { useLoad } from '../lib/useLoad'
import { submitForm } from '../lib/saveForm'
import type { AdminUser, Branch, Company, Role } from '../lib/types'
import {
  CheckField, DataTable, FormActions, FormError, Modal, PageHeader, PrimaryButton, SelectField, StatusBadge, TextField, td, th,
  type FieldErrors,
} from '../components/ui'

interface Catalogs {
  roles: Role[]
  companies: Company[]
  branches: Branch[]
}

function UserForm({ user, catalogs, isSelf, onClose, onSaved }: { user: AdminUser | null; catalogs: Catalogs; isSelf: boolean; onClose: () => void; onSaved: () => void }) {
  const [username, setUsername] = useState(user?.username ?? '')
  const [name, setName] = useState(user?.name ?? '')
  const [email, setEmail] = useState(user?.email ?? '')
  const [password, setPassword] = useState('')
  const [roleId, setRoleId] = useState(String(user?.role.id ?? catalogs.roles.find((r) => r.slug === 'vendedor')?.id ?? ''))
  const [active, setActive] = useState(user?.is_active ?? true)
  const [companyIds, setCompanyIds] = useState<number[]>(user?.company_ids ?? [])
  const [branchIds, setBranchIds] = useState<number[]>(user?.branch_ids ?? [])
  const [errors, setErrors] = useState<FieldErrors>({})
  const [message, setMessage] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)

  const toggle = (list: number[], id: number) => (list.includes(id) ? list.filter((x) => x !== id) : [...list, id])

  function toggleCompany(company: Company) {
    const removing = companyIds.includes(company.id)
    setCompanyIds(toggle(companyIds, company.id))
    if (removing) {
      const own = catalogs.branches.filter((b) => b.company_id === company.id).map((b) => b.id)
      setBranchIds(branchIds.filter((id) => !own.includes(id)))
    }
  }

  async function onSubmit(e: FormEvent) {
    e.preventDefault()
    setSaving(true)
    const body = {
      username, name, email: email || null, role_id: Number(roleId), is_active: active,
      company_ids: companyIds, branch_ids: branchIds,
      ...(password ? { password } : {}),
    }
    const r = await submitForm(user ? 'PUT' : 'POST', user ? `/admin/users/${user.id}` : '/admin/users', body)
    setSaving(false)
    if (r.ok) return onSaved()
    setErrors(r.errors)
    setMessage(r.message)
  }

  return (
    <Modal title={user ? 'Editar usuario' : 'Nuevo usuario'} onClose={onClose}>
      <form onSubmit={onSubmit} className="space-y-3" noValidate>
        <FormError message={message} />
        <TextField id="user-username" label="Nombre de usuario" value={username} onChange={(e) => setUsername(e.target.value)} autoCapitalize="none" autoComplete="off"
          hint="Sin espacios ni correo. Se guarda en minúsculas." errors={errors.username} />
        <TextField id="user-name" label="Nombre completo" value={name} onChange={(e) => setName(e.target.value)} errors={errors.name} />
        <TextField id="user-email" label="Correo (opcional)" type="email" value={email} onChange={(e) => setEmail(e.target.value)} errors={errors.email} />
        <TextField id="user-password" label={user ? 'Nueva contraseña (opcional)' : 'Contraseña'} type="password" autoComplete="new-password" value={password}
          onChange={(e) => setPassword(e.target.value)} hint="Mínimo 10 caracteres, con letras y números." errors={errors.password} />
        <SelectField id="user-role" label="Rol" value={roleId} onChange={(e) => setRoleId(e.target.value)} errors={errors.role_id}
          disabled={isSelf} hint={isSelf ? 'No puedes cambiar tu propio rol.' : undefined}>
          {catalogs.roles.map((r) => <option key={r.id} value={r.id}>{r.name}</option>)}
        </SelectField>
        <CheckField label="Usuario activo" checked={active} onChange={(e) => setActive(e.target.checked)} disabled={isSelf} />

        <fieldset className="rounded-md border border-line p-3">
          <legend className="px-1 text-sm font-medium">Ámbito de datos</legend>
          <p className="mb-2 text-xs text-jge-charcoal/70">
            El Administrador ve todo. Para los demás roles, marca las compañías; si no marcas sucursales, verá todas las de esa compañía.
          </p>
          {catalogs.companies.map((c) => (
            <div key={c.id} className="mb-2">
              <CheckField label={c.name} checked={companyIds.includes(c.id)} onChange={() => toggleCompany(c)} />
              {companyIds.includes(c.id) && (
                <div className="ml-6 mt-1 space-y-1">
                  {catalogs.branches.filter((b) => b.company_id === c.id).map((b) => (
                    <CheckField key={b.id} label={`Sucursal ${b.name}`} checked={branchIds.includes(b.id)} onChange={() => setBranchIds(toggle(branchIds, b.id))} />
                  ))}
                </div>
              )}
            </div>
          ))}
          {errors.company_ids && <p className="text-xs text-danger">Error: {errors.company_ids[0]}</p>}
          {errors.branch_ids && <p className="text-xs text-danger">Error: {errors.branch_ids[0]}</p>}
        </fieldset>
        <FormActions saving={saving} onCancel={onClose} />
      </form>
    </Modal>
  )
}

export default function UsersPage() {
  const { user: me } = useAuth()
  const users = useLoad<{ data: AdminUser[] }>('/admin/users')
  const roles = useLoad<{ data: Role[] }>('/admin/roles')
  const companies = useLoad<{ data: Company[] }>('/admin/companies')
  const branches = useLoad<{ data: Branch[] }>('/admin/branches')
  const [form, setForm] = useState<AdminUser | 'new' | null>(null)

  const catalogs: Catalogs = { roles: roles.data?.data ?? [], companies: companies.data?.data ?? [], branches: branches.data?.data ?? [] }
  const ready = !!(roles.data && companies.data && branches.data)
  const scopeLabel = (u: AdminUser) => (u.role.slug === 'administrador' ? 'Todo' : u.company_ids.length ? `${u.company_ids.length} compañía(s)` : 'Sin ámbito')

  return (
    <section>
      <PageHeader title="Usuarios" action={<PrimaryButton onClick={() => setForm('new')} disabled={!ready}>Nuevo usuario</PrimaryButton>} />
      <FormError message={users.error} />
      <DataTable label="Usuarios">
        <thead>
          <tr>
            <th className={th}>Usuario</th><th className={th}>Nombre</th><th className={th}>Rol</th><th className={th}>Ámbito</th>
            <th className={th}>Estado</th><th className={th}>Último acceso</th><th className={th}><span className="sr-only">Acciones</span></th>
          </tr>
        </thead>
        <tbody>
          {(users.data?.data ?? []).map((u) => (
            <tr key={u.id}>
              <td className={td}>{u.username}</td>
              <td className={td}>{u.name}</td>
              <td className={td}>{u.role.name}</td>
              <td className={td}>{scopeLabel(u)}</td>
              <td className={td}><StatusBadge active={u.is_active} /></td>
              <td className={td}>{u.last_login_at ? new Date(u.last_login_at).toLocaleString('es') : 'Nunca'}</td>
              <td className={`${td} text-right`}>
                <button className="text-jge-blue underline" disabled={!ready} onClick={() => setForm(u)} aria-label={`Editar usuario ${u.username}`}>Editar</button>
              </td>
            </tr>
          ))}
        </tbody>
      </DataTable>

      {form && (
        <UserForm
          user={form === 'new' ? null : form}
          catalogs={catalogs}
          isSelf={form !== 'new' && form.id === me?.id}
          onClose={() => setForm(null)}
          onSaved={() => { setForm(null); void users.reload() }}
        />
      )}
    </section>
  )
}
