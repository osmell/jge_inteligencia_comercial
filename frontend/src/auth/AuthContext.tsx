import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react'
import { api, ApiError } from '../lib/api'
import type { AuthUser } from './types'

interface AuthState {
  user: AuthUser | null
  loading: boolean
  login: (username: string, password: string) => Promise<void>
  logout: () => Promise<void>
  /** Reemplaza el usuario en memoria (por ejemplo, tras cambiar la clave). */
  replaceUser: (user: AuthUser) => void
  can: (permission?: string) => boolean
}

const AuthContext = createContext<AuthState | null>(null)

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<AuthUser | null>(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    api<{ data: AuthUser }>('/auth/me')
      .then((r) => setUser(r.data))
      .catch((e) => {
        if (!(e instanceof ApiError && (e.status === 401 || e.status === 403))) console.error(e)
      })
      .finally(() => setLoading(false))
  }, [])

  const login = useCallback(async (username: string, password: string) => {
    const r = await api<{ data: AuthUser }>('/auth/login', { method: 'POST', body: { username, password } })
    setUser(r.data)
  }, [])

  const logout = useCallback(async () => {
    try {
      await api('/auth/logout', { method: 'POST' })
    } finally {
      setUser(null)
    }
  }, [])

  const replaceUser = useCallback((next: AuthUser) => setUser(next), [])

  // Solo oculta controles; la autorización real se aplica en el backend.
  const can = useCallback((permission?: string) => !permission || !!user?.permissions.includes(permission), [user])

  const value = useMemo(() => ({ user, loading, login, logout, replaceUser, can }), [user, loading, login, logout, replaceUser, can])
  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

// eslint-disable-next-line react-refresh/only-export-components
export function useAuth(): AuthState {
  const ctx = useContext(AuthContext)
  if (!ctx) throw new Error('useAuth debe usarse dentro de AuthProvider')
  return ctx
}
