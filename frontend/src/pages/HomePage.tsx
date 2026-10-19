import { ArrowRight, Building2, GitBranch, UserCircle } from 'lucide-react'
import { Link } from 'react-router-dom'
import { useAuth } from '../auth/AuthContext'
import { NAV_ITEMS } from '../components/navigation'
import { Card, PageHeader } from '../components/ui'

function Stat({ icon: Icon, label, value, hint }: { icon: typeof Building2; label: string; value: string; hint?: string }) {
  return (
    <Card className="flex items-start gap-4 p-5">
      <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-jge-blue-50 text-jge-blue">
        <Icon className="h-5 w-5" aria-hidden="true" />
      </span>
      <div className="min-w-0">
        <p className="text-sm text-ink-muted">{label}</p>
        <p className="tabular truncate text-2xl font-semibold tracking-tight">{value}</p>
        {hint && <p className="mt-0.5 text-xs text-ink-muted">{hint}</p>}
      </div>
    </Card>
  )
}

export default function HomePage() {
  const { user, can } = useAuth()
  const isAdmin = user?.role.slug === 'administrador'
  const shortcuts = NAV_ITEMS.filter((i) => i.to !== '/' && can(i.permission))

  return (
    <section>
      <PageHeader title={`Hola, ${user?.name}`} description="Resumen de tu acceso en la plataforma." />

      <div className="grid gap-4 md:grid-cols-3">
        <Stat icon={UserCircle} label="Tu rol" value={user?.role.name ?? ''} />
        <Stat
          icon={Building2}
          label="Compañías en tu ámbito"
          value={isAdmin ? 'Todas' : String(user?.companies.length ?? 0)}
          hint={isAdmin ? 'Acceso sin restricción' : user?.companies.map((c) => c.name).join(', ') || 'Sin compañías asignadas'}
        />
        <Stat
          icon={GitBranch}
          label="Sucursales"
          value={isAdmin ? 'Todas' : user?.branches.length ? String(user.branches.length) : 'Todas las de tus compañías'}
          hint={!isAdmin && user?.branches.length ? user.branches.map((b) => b.name).join(', ') : undefined}
        />
      </div>

      {shortcuts.length > 0 && (
        <div className="mt-8">
          <h2 className="mb-3 text-sm font-semibold uppercase tracking-wide text-ink-muted">Accesos rápidos</h2>
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            {shortcuts.map((s) => (
              <Link
                key={s.to}
                to={s.to}
                className="group flex min-h-16 items-center gap-3 rounded-[var(--radius-card)] border border-line bg-surface p-4 shadow-[var(--shadow-card)] transition-colors duration-150 hover:border-jge-blue"
              >
                <s.icon className="h-5 w-5 text-jge-blue" aria-hidden="true" />
                <span className="flex-1 text-sm font-medium">{s.label}</span>
                <ArrowRight className="h-4 w-4 text-ink-subtle transition-transform duration-150 group-hover:translate-x-0.5" aria-hidden="true" />
              </Link>
            ))}
          </div>
        </div>
      )}
    </section>
  )
}
