import { useEffect, useMemo, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { useToast } from '../components/toast'
import { Alert, Button, Card, FormError, PageHeader, SelectField } from '../components/ui'
import { api, ApiError } from '../lib/api'
import { MENU_GROUPS, normalizeHidden, vanishingGroups, type MenuAccess } from '../lib/menuAccess'
import { useLoad } from '../lib/useLoad'

/** Casilla de grupo: marcada si se ven todas sus opciones disponibles, a medias si se ven algunas. */
function GroupCheck({ label, checked, mixed, disabled, onChange }: { label: string; checked: boolean; mixed: boolean; disabled: boolean; onChange: () => void }) {
  const ref = useRef<HTMLInputElement>(null)
  useEffect(() => {
    if (ref.current) ref.current.indeterminate = mixed
  }, [mixed])
  return (
    <label className="flex min-h-9 cursor-pointer items-center gap-2.5 text-sm font-semibold has-[:disabled]:cursor-not-allowed has-[:disabled]:text-ink-subtle">
      <input ref={ref} type="checkbox" className="h-4 w-4 cursor-pointer accent-jge-blue" checked={checked} disabled={disabled} onChange={onChange} aria-label={`Todo el grupo ${label}`} />
      {label}
    </label>
  )
}

const ALL_KEYS = MENU_GROUPS.flatMap((g) => g.items.map((i) => i.key))

/**
 * «Menú por usuario»: el administrador elige a una persona (incluido él mismo) y marca qué opciones del menú ve. Solo QUITA
 * (siempre hace falta el permiso de su rol) y el servidor lo hace cumplir; un grupo sin opciones visibles desaparece del menú.
 */
export default function MenuAccessPage() {
  const toast = useToast()
  const access = useLoad<{ data: MenuAccess }>('/admin/menu-access')
  const users = useMemo(() => access.data?.data.users ?? [], [access.data])
  const [userId, setUserId] = useState<number | null>(null)
  const [draft, setDraft] = useState<string[] | null>(null)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const user = users.find((u) => u.id === (userId ?? users[0]?.id)) ?? null
  const hidden = draft ?? user?.hidden ?? []
  const available = user?.available ?? []
  const dirty = !!user && draft !== null && normalizeHidden(draft).join() !== normalizeHidden(user.hidden).join()
  const vanish = vanishingGroups(available, hidden)

  function toggle(key: string) {
    setDraft(hidden.includes(key) ? hidden.filter((k) => k !== key) : [...hidden, key])
  }

  function toggleGroup(keys: string[]) {
    const open = keys.filter((k) => available.includes(k))
    const allVisible = open.every((k) => !hidden.includes(k))
    setDraft(allVisible ? [...hidden, ...open.filter((k) => !hidden.includes(k))] : hidden.filter((k) => !open.includes(k)))
  }

  async function save() {
    if (!user) return
    setSaving(true)
    setError(null)
    try {
      await api(`/admin/menu-access/${user.id}`, { method: 'PUT', body: { hidden: normalizeHidden(hidden) } })
      toast.success(`Menú de ${user.name} actualizado.`)
      await access.reload()
      setDraft(null)
    } catch (e) {
      setError(e instanceof ApiError ? (e.errors.hidden?.[0] ?? e.message) : 'No se pudo guardar el menú.')
    } finally {
      setSaving(false)
    }
  }

  return (
    <section>
      <PageHeader
        title="Menú por usuario"
        description="Elige qué opciones del menú ve cada persona. Solo puedes quitar opciones: lo que su rol no le permite no se puede dar desde aquí."
        action={
          <div className="flex gap-2">
            <Button variant="secondary" disabled={!dirty || saving} onClick={() => setDraft(null)}>Descartar</Button>
            <Button loading={saving} disabled={!dirty} onClick={() => void save()}>Guardar cambios</Button>
          </div>
        }
      />
      <div className="mb-4 space-y-2">
        <Alert tone="info">
          Una opción oculta desaparece del menú y de la barra de comandos, y el servidor rechaza esa pantalla y sus datos. Si una pantalla comparte datos con otra opción que sigue visible (la ficha de un cliente muestra su crédito, por ejemplo), esos datos siguen disponibles para esa otra opción. Si desmarcas todas las opciones de un grupo, el grupo desaparece del menú. Un administrador también puede ocultarse opciones de su propio menú; esta pantalla («Menú por usuario») nunca se oculta, así que siempre puede volver a mostrarlas. El menú de la persona se actualiza al recargar la página o volver a iniciar sesión; el servidor aplica el cambio de inmediato.
        </Alert>
        <FormError message={access.error ?? error} />
      </div>

      {access.data && users.length === 0 && <Alert tone="info">No hay usuarios.</Alert>}

      {user && (
        <>
          <div className="mb-4 max-w-md">
            <SelectField id="menu-user" label="Persona" value={String(user.id)} disabled={dirty || saving} onChange={(e) => { setUserId(Number(e.target.value)); setDraft(null) }}>
              {users.map((u) => <option key={u.id} value={u.id}>{u.name} (@{u.username}) · {u.role.name}{u.is_active ? '' : ' · inactivo'}</option>)}
            </SelectField>
            {dirty && <p className="mt-1 text-xs text-ink-muted">Hay cambios sin guardar: guárdalos o descártalos para elegir a otra persona.</p>}
          </div>

          {available.length < ALL_KEYS.length && (
            <div className="mb-4">
              <Alert tone="info">
                El rol {user.role.name} permite {available.length} de {ALL_KEYS.length} opciones del menú; las demás salen deshabilitadas porque este menú solo quita opciones, no las otorga.
                {' '}Para que {user.name} pueda ver más, otorgue los permisos de ese rol en <Link to="/admin/roles" className="font-medium text-jge-blue underline">Roles y permisos</Link> y vuelva aquí para ocultar lo que no quiera mostrarle.
              </Alert>
            </div>
          )}

          {vanish.length > 0 && (
            <div className="mb-4"><Alert tone="warning">{user.name} dejará de ver {vanish.length === 1 ? 'el grupo' : 'los grupos'} {vanish.join(', ')}: ya no le queda ninguna opción visible.</Alert></div>
          )}

          <div className="grid gap-4 md:grid-cols-2">
            {MENU_GROUPS.map((g) => {
              const keys = g.items.map((i) => i.key)
              const open = keys.filter((k) => available.includes(k))
              const visible = open.filter((k) => !hidden.includes(k))
              return (
                <Card key={g.title} className="p-4">
                  <GroupCheck label={g.title} checked={open.length > 0 && visible.length === open.length} mixed={visible.length > 0 && visible.length < open.length} disabled={open.length === 0} onChange={() => toggleGroup(keys)} />
                  <ul className="mt-1 space-y-0.5 border-l border-line pl-4" aria-label={g.title}>
                    {g.items.map((i) => {
                      const allowed = available.includes(i.key)
                      return (
                        <li key={i.key}>
                          <label className="flex min-h-9 cursor-pointer items-center gap-2.5 text-sm has-[:disabled]:cursor-not-allowed has-[:disabled]:text-ink-subtle">
                            <input type="checkbox" className="h-4 w-4 cursor-pointer accent-jge-blue" checked={allowed && !hidden.includes(i.key)} disabled={!allowed} onChange={() => toggle(i.key)} aria-label={`${g.title}: ${i.title ?? i.label}`} />
                            <span>{i.label}{allowed ? '' : <span className="ml-2 text-xs">su rol no la permite</span>}</span>
                          </label>
                        </li>
                      )
                    })}
                  </ul>
                </Card>
              )
            })}
          </div>

          <div className="mt-4">
            <Button variant="secondary" disabled={saving || hidden.length === 0} onClick={() => setDraft([])}>Mostrar todo</Button>
          </div>
        </>
      )}
    </section>
  )
}
