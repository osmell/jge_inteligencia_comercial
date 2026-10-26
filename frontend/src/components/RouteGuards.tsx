import { Loader2 } from 'lucide-react'
import { Navigate, Outlet, useLocation } from 'react-router-dom'
import { useAuth } from '../auth/AuthContext'
import { optionForPath } from '../lib/menu'

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

/** Una opción que la administración ocultó tampoco se abre escribiendo la dirección (el servidor además rechaza sus datos). */
export function RequireVisibleOption() {
  const { canSee } = useAuth()
  const { pathname } = useLocation()
  const option = optionForPath(pathname)
  return option && option.key && !canSee({ key: option.key }) ? <Navigate to="/" replace /> : <Outlet />
}

/** Pantallas de uso exclusivo del rol Administrador. */
export function RequireAdministrator() {
  const { user } = useAuth()
  return user?.role.slug === 'administrador' ? <Outlet /> : <Navigate to="/" replace />
}
