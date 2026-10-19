import { Eye, EyeOff, LineChart, ShieldCheck, Boxes } from 'lucide-react'
import { useState, type FormEvent } from 'react'
import { Navigate } from 'react-router-dom'
import { useAuth } from '../auth/AuthContext'
import { BrandMark } from '../components/Brand'
import { Button, FormError } from '../components/ui'
import { ApiError } from '../lib/api'

const highlights = [
  { icon: LineChart, text: 'Ventas por compañía, sucursal, vendedor y cliente' },
  { icon: Boxes, text: 'Crédito, cobranzas e inventario en un solo lugar' },
  { icon: ShieldCheck, text: 'Acceso por rol con trazabilidad completa' },
]

export default function LoginPage() {
  const { user, loading, login } = useAuth()
  const [username, setUsername] = useState('')
  const [password, setPassword] = useState('')
  const [showPassword, setShowPassword] = useState(false)
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

  const input = 'w-full min-h-11 rounded-lg border border-line-strong bg-surface px-3 text-sm text-ink transition-colors hover:border-ink-subtle'

  return (
    <div className="flex min-h-full">
      <aside className="on-dark relative hidden w-[46%] max-w-xl flex-col justify-between overflow-hidden bg-jge-navy p-10 text-white lg:flex">
        <div className="pointer-events-none absolute -right-24 -top-24 h-72 w-72 rounded-full bg-jge-navy-soft" aria-hidden="true" />
        <div className="pointer-events-none absolute -bottom-32 -left-16 h-80 w-80 rounded-full bg-jge-navy-soft/60" aria-hidden="true" />
        <div className="relative flex items-center gap-3">
          <BrandMark className="h-11 w-11" />
          <div className="leading-tight">
            <p className="font-semibold">JGE Soluciones Integrales</p>
            <p className="text-sm text-white/70">Inteligencia Comercial</p>
          </div>
        </div>
        <div className="relative">
          <div className="mb-5 h-1 w-14 rounded bg-jge-red" aria-hidden="true" />
          <p className="text-3xl font-semibold leading-tight tracking-tight">Decisiones comerciales basadas en datos confiables.</p>
          <ul className="mt-8 space-y-4">
            {highlights.map((h) => (
              <li key={h.text} className="flex items-start gap-3 text-sm text-white/85">
                <h.icon className="mt-0.5 h-5 w-5 shrink-0 text-white" aria-hidden="true" />
                {h.text}
              </li>
            ))}
          </ul>
        </div>
        <p className="relative text-xs text-white/50">Uso interno. Las acciones quedan registradas.</p>
      </aside>

      <main className="flex flex-1 items-center justify-center px-4 py-10">
        <div className="w-full max-w-sm">
          <div className="mb-8 flex items-center gap-3 lg:hidden">
            <span className="rounded-lg bg-jge-navy p-0.5"><BrandMark className="h-9 w-9" /></span>
            <p className="font-semibold">JGE Inteligencia Comercial</p>
          </div>
          <h1 className="text-2xl font-semibold tracking-tight">Iniciar sesión</h1>
          <p className="mt-1 text-sm text-ink-muted">Ingresa con tu nombre de usuario y contraseña.</p>

          <form onSubmit={onSubmit} className="mt-6 space-y-4" noValidate>
            <FormError message={error} />
            <div>
              <label htmlFor="username" className="mb-1 block text-sm font-medium">Usuario</label>
              <input id="username" autoComplete="username" autoCapitalize="none" spellCheck={false} required value={username} onChange={(e) => setUsername(e.target.value)} className={input} />
            </div>
            <div>
              <label htmlFor="password" className="mb-1 block text-sm font-medium">Contraseña</label>
              <div className="relative">
                <input
                  id="password"
                  type={showPassword ? 'text' : 'password'}
                  autoComplete="current-password"
                  required
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  className={`${input} pr-11`}
                />
                <button
                  type="button"
                  onClick={() => setShowPassword((v) => !v)}
                  aria-label={showPassword ? 'Ocultar contraseña' : 'Mostrar contraseña'}
                  aria-pressed={showPassword}
                  className="absolute right-1 top-1/2 flex h-9 w-9 -translate-y-1/2 cursor-pointer items-center justify-center rounded-lg text-ink-muted hover:bg-canvas"
                >
                  {showPassword ? <EyeOff className="h-4 w-4" aria-hidden="true" /> : <Eye className="h-4 w-4" aria-hidden="true" />}
                </button>
              </div>
            </div>
            <Button type="submit" className="w-full" loading={submitting} disabled={!username || !password}>
              {submitting ? 'Ingresando…' : 'Ingresar'}
            </Button>
          </form>
        </div>
      </main>
    </div>
  )
}
