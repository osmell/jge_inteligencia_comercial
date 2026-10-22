import { Pencil, Plus, Users } from 'lucide-react'
import { useState, type FormEvent } from 'react'
import { useAuth } from '../auth/AuthContext'
import { useFormValues } from '../lib/useFormValues'
import { useLoad } from '../lib/useLoad'
import { submitForm } from '../lib/saveForm'
import type { AdminUser, Branch, Company, Role } from '../lib/types'
import { useToast } from '../components/toast'
import {
  Badge, Button, CheckField, DataTable, EmptyRow, FormActions, FormError, Modal, PageHeader, SearchInput, SelectField, StatusBadge,
  TableSkeleton, TextField, td, th, tr, type FieldErrors,
} from '../components/ui'

interface Catalogs {
  roles: Role[]
  companies: Company[]
  branches: Branch[]
}

function UserForm({ user, catalogs, isSelf, onClose, onSaved }: { user: AdminUser | null; catalogs: Catalogs; isSelf: boolean; onClose: () => void; onSaved: () => void }) {
  const { values: v, set } = useFormValues({
    username: user?.username ?? '', name: user?.name ?? '', email: user?.email ?? '', password: '',
    roleId: String(user?.role.id ?? catalogs.roles.find((r) => r.slug === 'vendedor')?.id ?? ''), active: user?.is_active ?? true,
    companyIds: user?.company_ids ?? [] as number[], branchIds: user?.branch_ids ?? [] as number[],
  })
  const [errors, setErrors] = useState<FieldErrors>({})
  const [message, setMessage] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)

  const toggle = (list: number[], id: number) => (list.includes(id) ? list.filter((x) => x !== id) : [...list, id])

  function toggleCompany(company: Company) {
    const removing = v.companyIds.includes(company.id)
    set('companyIds', toggle(v.companyIds, company.id))
    if (removing) {
      const own = catalogs.branches.filter((b) => b.company_id === company.id).map((b) => b.id)
      set('branchIds', v.branchIds.filter((id) => !own.includes(id)))
    }
  }

  async function onSubmit(e: FormEvent) {
    e.preventDefault()
    setSaving(true)
    const body = {
      username: v.username, name: v.name, email: v.email || null, role_id: Number(v.roleId), is_active: v.active,
      company_ids: v.companyIds, branch_ids: v.branchIds,
      ...(v.password ? { password: v.password } : {}),
    }
    const r = await submitForm(user ? 'PUT' : 'POST', user ? `/admin/users/${user.id}` : '/admin/users', body)
    setSaving(false)
    if (r.ok) return onSaved()
    setErrors(r.errors)
    setMessage(r.message)
  }

  return (
    <Modal title={user ? 'Editar usuario' : 'Nuevo usuario'} onClose={onClose} wide>
      <form onSubmit={onSubmit} className="space-y-4" noValidate>
        <FormError message={message} />
        <div className="grid gap-4 sm:grid-cols-2">
          <TextField id="user-username" label="Nombre de usuario" value={v.username} onChange={(e) => set('username', e.target.value)} autoCapitalize="none" autoComplete="off"
            hint="Sin espacios ni correo. Se guarda en minúsculas." errors={errors.username} />
          <TextField id="user-name" label="Nombre completo" value={v.name} onChange={(e) => set('name', e.target.value)} errors={errors.name} />
          <TextField id="user-email" label="Correo (opcional)" type="email" value={v.email} onChange={(e) => set('email', e.target.value)} errors={errors.email} />
          <TextField id="user-password" label={user ? 'Nueva contraseña (opcional)' : 'Contraseña'} type="password" autoComplete="new-password" value={v.password}
            onChange={(e) => set('password', e.target.value)} hint="Mínimo 10 caracteres, con letras y números." errors={errors.password} />
          <SelectField id="user-role" label="Rol" value={v.roleId} onChange={(e) => set('roleId', e.target.value)} errors={errors.role_id}
            disabled={isSelf} hint={isSelf ? 'No puedes cambiar tu propio rol.' : undefined}>
            {catalogs.roles.map((r) => <option key={r.id} value={r.id}>{r.name}</option>)}
          </SelectField>
          <div className="self-end pb-1">
            <CheckField label="Usuario activo" checked={v.active} onChange={(e) => set('active', e.target.checked)} disabled={isSelf} />
            {isSelf && <p className="text-xs text-ink-muted">No puedes desactivar tu propia cuenta.</p>}
          </div>
        </div>

        <fieldset className="rounded-lg border border-line p-4">
          <legend className="px-1 text-sm font-semibold">Ámbito de datos</legend>
          <p className="mb-3 text-xs text-ink-muted">
            El Administrador ve todo. Para los demás roles, marca las compañías; si no marcas sucursales, verá todas las de esa compañía.
          </p>
          <div className="grid gap-x-6 gap-y-2 sm:grid-cols-2">
            {catalogs.companies.map((c) => (
              <div key={c.id}>
                <CheckField label={c.name} checked={v.companyIds.includes(c.id)} onChange={() => toggleCompany(c)} />
                {v.companyIds.includes(c.id) && (
                  <div className="ml-6 border-l-2 border-line pl-3">
                    {catalogs.branches.filter((b) => b.company_id === c.id).map((b) => (
                      <CheckField key={b.id} label={`Sucursal ${b.name}`} checked={v.branchIds.includes(b.id)} onChange={() => set('branchIds', toggle(v.branchIds, b.id))} />
                    ))}
                  </div>
                )}
              </div>
            ))}
          </div>
          {errors.company_ids && <p className="mt-2 text-xs font-medium text-danger">Error: {errors.company_ids[0]}</p>}
          {errors.branch_ids && <p className="mt-2 text-xs font-medium text-danger">Error: {errors.branch_ids[0]}</p>}
        </fieldset>
        <FormActions saving={saving} onCancel={onClose} />
      </form>
    </Modal>
  )
}

export default function UsersPage() {
  const { user: me } = useAuth()
  const toast = useToast()
  const users = useLoad<{ data: AdminUser[] }>('/admin/users')
  const roles = useLoad<{ data: Role[] }>('/admin/roles')
  const companies = useLoad<{ data: Company[] }>('/admin/companies')
  const branches = useLoad<{ data: Branch[] }>('/admin/branches')
  const [form, setForm] = useState<AdminUser | 'new' | null>(null)
  const [query, setQuery] = useState('')

  const catalogs: Catalogs = { roles: roles.data?.data ?? [], companies: companies.data?.data ?? [], branches: branches.data?.data ?? [] }
  const ready = !!(roles.data && companies.data && branches.data)
  const scopeLabel = (u: AdminUser) => (u.role.slug === 'administrador' ? 'Todo' : u.company_ids.length ? `${u.company_ids.length} compañía(s)` : 'Sin ámbito')
  const q = query.trim().toLowerCase()
  const shown = (users.data?.data ?? []).filter((u) => !q || [u.username, u.name, u.role.name].some((v) => v.toLowerCase().includes(q)))

  return (
    <section>
      <PageHeader
        title="Usuarios"
        description="Cuentas de acceso, roles y ámbito de datos. Los usuarios no se eliminan: se desactivan."
        action={<Button icon={<Plus className="h-4 w-4" aria-hidden="true" />} onClick={() => setForm('new')} disabled={!ready}>Nuevo usuario</Button>}
      />
      <div className="mb-3"><SearchInput label="Buscar usuarios" value={query} onChange={setQuery} placeholder="Buscar por usuario, nombre o rol…" /></div>
      <FormError message={users.error} />
      <DataTable label="Usuarios">
        <thead>
          <tr>
            <th className={th}>Usuario</th><th className={th}>Nombre</th><th className={th}>Rol</th><th className={th}>Ámbito</th>
            <th className={th}>Estado</th><th className={th}>Último acceso</th><th className={th}><span className="sr-only">Acciones</span></th>
          </tr>
        </thead>
        <tbody>
          {users.loading && !users.data && <TableSkeleton cols={7} />}
          {shown.map((u) => (
            <tr key={u.id} className={tr}>
              <td className={`${td} font-mono text-[13px]`}>{u.username}</td>
              <td className={`${td} font-medium`}>{u.name}</td>
              <td className={td}><Badge>{u.role.name}</Badge></td>
              <td className={td}>{scopeLabel(u)}</td>
              <td className={td}><StatusBadge active={u.is_active} /></td>
              <td className={`${td} tabular text-ink-muted`}>{u.last_login_at ? new Date(u.last_login_at).toLocaleString('es') : 'Nunca'}</td>
              <td className={`${td} text-right`}>
                <Button variant="ghost" small icon={<Pencil className="h-4 w-4" aria-hidden="true" />} disabled={!ready} onClick={() => setForm(u)} aria-label={`Editar usuario ${u.username}`}>Editar</Button>
              </td>
            </tr>
          ))}
          {!users.loading && shown.length === 0 && (
            <EmptyRow cols={7}><Users className="mx-auto mb-2 h-6 w-6 text-ink-subtle" aria-hidden="true" />{q ? 'Ningún usuario coincide con la búsqueda.' : 'No hay usuarios registrados.'}</EmptyRow>
          )}
        </tbody>
      </DataTable>

      {form && (
        <UserForm
          user={form === 'new' ? null : form}
          catalogs={catalogs}
          isSelf={form !== 'new' && form.id === me?.id}
          onClose={() => setForm(null)}
          onSaved={() => { toast.success(form === 'new' ? 'Usuario creado.' : 'Usuario actualizado.'); setForm(null); void users.reload() }}
        />
      )}
    </section>
  )
}
