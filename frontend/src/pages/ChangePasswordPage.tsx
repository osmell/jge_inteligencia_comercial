import { KeyRound } from 'lucide-react'
import { type FormEvent } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useAuth } from '../auth/AuthContext'
import type { AuthUser } from '../auth/types'
import { BrandLogo } from '../components/Brand'
import { useToast } from '../components/toast'
import { Alert, Button, TextField } from '../components/ui'
import { api } from '../lib/api'
import { PASSWORD_HINT } from '../lib/passwordPolicy'
import { useFormValues } from '../lib/useFormValues'
import { useSubmit } from '../lib/useSubmit'

/**
 * Cambio de la propia clave. Si la cuenta tiene una clave temporal (alta nueva o restablecida por administración) es obligatorio y no hay
 * nada más que hacer hasta cambiarla; si no, se llega desde el encabezado y se puede volver. La regla se aplica en el servidor.
 */
export default function ChangePasswordPage() {
  const { user, replaceUser, logout } = useAuth()
  const toast = useToast()
  const navigate = useNavigate()
  const forced = user?.must_change_password === true
  const { values: v, set } = useFormValues({ current: '', next: '', confirmation: '' })
  const { errors, saving, run } = useSubmit()

  async function onSubmit(e: FormEvent) {
    e.preventDefault()
    const ok = await run(async () => {
      const res = await api<{ data: AuthUser }>('/auth/password', { method: 'POST', body: { current_password: v.current, password: v.next, password_confirmation: v.confirmation } })
      replaceUser(res.data)
    }, 'password')
    if (ok) {
      toast.success('Clave cambiada.')
      navigate('/', { replace: true })
    }
  }

  return (
    <main className="flex min-h-full items-center justify-center px-4 py-10">
      <div className="w-full max-w-sm">
        <BrandLogo className="mb-6 h-16 w-auto" />
        <h1 className="flex items-center gap-2 text-2xl font-semibold tracking-tight">
          <KeyRound className="h-6 w-6 text-jge-blue" aria-hidden="true" />
          {forced ? 'Crea tu clave' : 'Cambiar mi clave'}
        </h1>
        {forced ? (
          <div className="mt-3"><Alert tone="info">Tu clave actual es temporal. Antes de usar el sistema debes crear una propia que solo tú conozcas.</Alert></div>
        ) : (
          <p className="mt-1 text-sm text-ink-muted">Se te pedirá la clave actual y se cerrará la sesión en otros equipos solo cuando caduque.</p>
        )}

        <form onSubmit={onSubmit} className="mt-6 space-y-4" noValidate>
          <TextField id="current-password" label={forced ? 'Clave temporal' : 'Clave actual'} type="password" autoComplete="current-password" value={v.current}
            errors={errors.current_password} onChange={(e) => set('current', e.target.value)} />
          <TextField id="new-password" label="Clave nueva" type="password" autoComplete="new-password" value={v.next} hint={PASSWORD_HINT}
            errors={errors.password} onChange={(e) => set('next', e.target.value)} />
          <TextField id="confirm-password" label="Repite la clave nueva" type="password" autoComplete="new-password" value={v.confirmation}
            onChange={(e) => set('confirmation', e.target.value)} />
          <Button type="submit" className="w-full" loading={saving} disabled={!v.current || !v.next || !v.confirmation}>Guardar clave</Button>
        </form>

        <div className="mt-6 text-center text-sm">
          {forced ? (
            <button type="button" className="cursor-pointer text-ink-muted hover:underline" onClick={() => void logout()}>Salir sin cambiarla</button>
          ) : (
            <Link className="text-jge-blue hover:underline" to="/">← Volver</Link>
          )}
        </div>
      </div>
    </main>
  )
}
