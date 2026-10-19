import { Navigate, Outlet } from 'react-router-dom'
import { useAuth } from '../auth/AuthContext'

export function RequireAuth() {
  const { user, loading } = useAuth()

  if (loading) return <p className="p-6 text-sm" role="status">Cargando…</p>
  return user ? <Outlet /> : <Navigate to="/login" replace />
}

export function RequirePermission({ permission }: { permission: string }) {
  const { can } = useAuth()
  return can(permission) ? <Outlet /> : <Navigate to="/" replace />
}
