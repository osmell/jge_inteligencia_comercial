import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react'
import { api, ApiError, SESSION_EXPIRED_EVENT } from '../lib/api'
import type { AuthUser } from './types'

interface AuthState {
  user: AuthUser | null
  loading: boolean
  login: (username: string, password: string) => Promise<void>
  logout: () => Promise<void>
  /** Reemplaza el usuario en memoria (por ejemplo, tras cambiar la clave). */
  replaceUser: (user: AuthUser) => void
  can: (permission?: string) => boolean
  /** ¿Se muestra esta opción del menú? Exige su permiso, que la administración no la haya ocultado y, si es solo de administración, el rol Administrador. */
  canSee: (item: { permission?: string; key?: string; adminOnly?: boolean }) => boolean
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

  // Una respuesta 401 en cualquier pantalla (sesión cerrada desde otro equipo, clave cambiada…) devuelve a iniciar sesión.
  useEffect(() => {
    const expired = () => setUser(null)
    window.addEventListener(SESSION_EXPIRED_EVENT, expired)
    return () => window.removeEventListener(SESSION_EXPIRED_EVENT, expired)
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

  // Ocultar una opción solo QUITA: sigue haciendo falta el permiso. El servidor también lo hace cumplir (403 `menu_option_hidden`).
  const canSee = useCallback(
    (item: { permission?: string; key?: string; adminOnly?: boolean }) =>
      can(item.permission) && !(item.key && user?.hidden_menu?.includes(item.key)) && (!item.adminOnly || user?.role.slug === 'administrador'),
    [can, user],
  )

  const value = useMemo(() => ({ user, loading, login, logout, replaceUser, can, canSee }), [user, loading, login, logout, replaceUser, can, canSee])
  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

/** La persona con sesión, o null si no hay proveedor (componentes que pueden vivir sin él, como el pie de una impresión). */
// eslint-disable-next-line react-refresh/only-export-components
export function useCurrentUser(): AuthUser | null {
  return useContext(AuthContext)?.user ?? null
}

// eslint-disable-next-line react-refresh/only-export-components
export function useAuth(): AuthState {
  const ctx = useContext(AuthContext)
  if (!ctx) throw new Error('useAuth debe usarse dentro de AuthProvider')
  return ctx
}
