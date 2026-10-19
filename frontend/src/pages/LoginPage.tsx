import { useState, type FormEvent } from 'react'
import { Navigate } from 'react-router-dom'
import { useAuth } from '../auth/AuthContext'
import { ApiError } from '../lib/api'

export default function LoginPage() {
  const { user, loading, login } = useAuth()
  const [username, setUsername] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)

  if (!loading && user) return <Navigate to="/" replace />

  async function onSubmit(e: FormEvent) {
    e.preventDefault()
    setError(null)
    setSubmitting(true)
    try {
      await login(username, password)
    } catch (err) {
      if (err instanceof ApiError) setError(err.errors.username?.[0] ?? err.message)
      else setError('No se pudo conectar con el servidor.')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="flex min-h-full items-center justify-center px-4">
      <div className="w-full max-w-sm rounded-lg border border-line bg-surface p-6 shadow-sm">
        <div className="mb-6 text-center">
          <span className="inline-block rounded bg-jge-blue px-3 py-1 text-2xl font-bold tracking-tight text-white">JGE</span>
          <div className="mx-auto mt-2 h-1 w-12 bg-jge-red" aria-hidden="true" />
          <h1 className="mt-3 text-lg font-semibold">Inteligencia Comercial</h1>
        </div>

        <form onSubmit={onSubmit} className="space-y-4" noValidate>
          {error && (
            <p role="alert" className="rounded-md border border-danger bg-red-50 px-3 py-2 text-sm text-danger">
              <span className="font-semibold">Error: </span>
              {error}
            </p>
          )}
          <div>
            <label htmlFor="username" className="mb-1 block text-sm font-medium">Usuario</label>
            <input
              id="username"
              autoComplete="username"
              autoCapitalize="none"
              required
              value={username}
              onChange={(e) => setUsername(e.target.value)}
              className="w-full rounded-md border border-line px-3 py-2 text-sm"
            />
          </div>
          <div>
            <label htmlFor="password" className="mb-1 block text-sm font-medium">Contraseña</label>
            <input
              id="password"
              type="password"
              autoComplete="current-password"
              required
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              className="w-full rounded-md border border-line px-3 py-2 text-sm"
            />
          </div>
          <button
            type="submit"
            disabled={submitting || !username || !password}
            className="w-full rounded-md bg-jge-blue px-3 py-2 text-sm font-semibold text-white hover:bg-jge-blue-dark disabled:opacity-50"
          >
            {submitting ? 'Ingresando…' : 'Ingresar'}
          </button>
        </form>
      </div>
    </div>
  )
}
