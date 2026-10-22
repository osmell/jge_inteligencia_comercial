import { Loader2 } from 'lucide-react'
import { Navigate, Outlet } from 'react-router-dom'
import { useAuth } from '../auth/AuthContext'

function Loading() {
  return (
    <div className="flex h-full items-center justify-center gap-2 text-sm text-ink-muted" role="status">
      <Loader2 className="h-5 w-5 animate-spin" aria-hidden="true" />
      Cargando…
    </div>
  )
}

/** Solo exige sesión (para la pantalla de cambio de clave, a la que también llega quien está obligado a cambiarla). */
export function RequireSession() {
  const { user, loading } = useAuth()

  if (loading) return <Loading />
  return user ? <Outlet /> : <Navigate to="/login" replace />
}

/** Sesión iniciada y clave propia: con una clave temporal solo se puede ir a cambiarla. */
export function RequireAuth() {
  const { user, loading } = useAuth()

  if (loading) return <Loading />
  if (!user) return <Navigate to="/login" replace />
  return user.must_change_password ? <Navigate to="/cambiar-clave" replace /> : <Outlet />
}

export function RequirePermission({ permission }: { permission: string }) {
  const { can } = useAuth()
  return can(permission) ? <Outlet /> : <Navigate to="/" replace />
}
