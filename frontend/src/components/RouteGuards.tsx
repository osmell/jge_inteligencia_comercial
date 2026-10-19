import { Loader2 } from 'lucide-react'
import { Navigate, Outlet } from 'react-router-dom'
import { useAuth } from '../auth/AuthContext'

export function RequireAuth() {
  const { user, loading } = useAuth()

  if (loading) {
    return (
      <div className="flex h-full items-center justify-center gap-2 text-sm text-ink-muted" role="status">
        <Loader2 className="h-5 w-5 animate-spin" aria-hidden="true" />
        Cargando…
      </div>
    )
  }
  return user ? <Outlet /> : <Navigate to="/login" replace />
}

export function RequirePermission({ permission }: { permission: string }) {
  const { can } = useAuth()
  return can(permission) ? <Outlet /> : <Navigate to="/" replace />
}
