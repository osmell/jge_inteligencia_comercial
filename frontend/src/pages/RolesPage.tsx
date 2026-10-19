import { Lock } from 'lucide-react'
import { useState } from 'react'
import { api, ApiError } from '../lib/api'
import { useLoad } from '../lib/useLoad'
import type { RoleMatrix, RoleMatrixRow } from '../lib/types'
import { useToast } from '../components/toast'
import { Alert, Button, Card, FormError, PageHeader, TableSkeleton, th, td, tr } from '../components/ui'

/** Matriz de permisos: cada fila es una opción del menú y cada columna un rol. */
export default function RolesPage() {
  const toast = useToast()
  const matrix = useLoad<{ data: RoleMatrix }>('/admin/role-permissions')
  // Solo se guardan en el borrador los roles que el usuario modificó.
  const [draft, setDraft] = useState<Record<number, string[]>>({})
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const data = matrix.data?.data
  const grantsOf = (r: RoleMatrixRow) => draft[r.id] ?? r.permissions
  const changed = (data?.roles ?? []).filter((r) => !r.locked && r.id in draft && [...draft[r.id]].sort().join() !== [...r.permissions].sort().join())

  function toggle(role: RoleMatrixRow, slug: string) {
    const current = grantsOf(role)
    setDraft({ ...draft, [role.id]: current.includes(slug) ? current.filter((s) => s !== slug) : [...current, slug] })
  }

  async function save() {
    setSaving(true)
    setError(null)
    try {
      for (const role of changed) {
        await api(`/admin/role-permissions/${role.id}`, { method: 'PUT', body: { permissions: draft[role.id] } })
      }
      toast.success('Permisos actualizados.')
      await matrix.reload()
      setDraft({})
    } catch (e) {
      setError(e instanceof ApiError ? (e.errors.permissions?.[0] ?? e.message) : 'No se pudieron guardar los cambios.')
    } finally {
      setSaving(false)
    }
  }

  return (
    <section>
      <PageHeader
        title="Roles y permisos"
        description="Cada permiso habilita una opción del menú y sus acciones. Marca qué roles pueden usar cada una."
        action={
          <div className="flex gap-2">
            <Button variant="secondary" disabled={changed.length === 0 || saving} onClick={() => setDraft({})}>
              Descartar
            </Button>
            <Button loading={saving} disabled={changed.length === 0} onClick={() => void save()}>Guardar cambios</Button>
          </div>
        }
      />
      <div className="mb-4 space-y-2">
        <Alert tone="info">
          El Administrador siempre tiene todos los permisos. Los cambios se aplican de inmediato en el servidor; el menú de los usuarios afectados se actualiza al recargar la página o volver a iniciar sesión.
        </Alert>
        <FormError message={matrix.error ?? error} />
      </div>

      <Card className="overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full text-left text-sm" aria-label="Permisos por rol">
            <thead>
              <tr>
                <th className={th}>Opción del menú</th>
                {(data?.roles ?? []).map((r) => (
                  <th key={r.id} className={`${th} text-center`}>
                    {r.name}
                    {r.locked && <Lock className="ml-1 inline h-3 w-3" aria-label="Bloqueado" />}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {matrix.loading && !data && <TableSkeleton cols={5} />}
              {(data?.permissions ?? []).map((p) => (
                <tr key={p.slug} className={tr}>
                  <td className={td}>
                    <p className="font-medium">{p.name}</p>
                    <p className="font-mono text-xs text-ink-muted">{p.slug}</p>
                    {p.admin_only && <p className="text-xs text-ink-muted">Solo el Administrador.</p>}
                  </td>
                  {data!.roles.map((r) => {
                    const locked = r.locked || (p.admin_only && !r.locked)
                    const checked = r.locked || grantsOf(r).includes(p.slug)
                    return (
                      <td key={r.id} className={`${td} text-center`}>
                        <input
                          type="checkbox"
                          className="h-5 w-5 cursor-pointer accent-jge-blue disabled:cursor-not-allowed"
                          checked={checked}
                          disabled={locked}
                          onChange={() => toggle(r, p.slug)}
                          aria-label={`${r.name}: ${p.name}`}
                        />
                      </td>
                    )
                  })}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Card>
    </section>
  )
}
