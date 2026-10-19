import { useAuth } from '../auth/AuthContext'

export default function HomePage() {
  const { user } = useAuth()

  return (
    <section>
      <h1 className="text-xl font-semibold">Hola, {user?.name}</h1>
      <p className="mt-1 text-sm text-jge-charcoal/70">Rol: {user?.role.name}</p>

      <div className="mt-6 grid gap-4 md:grid-cols-2">
        <div className="rounded-lg border border-line bg-surface p-4">
          <h2 className="text-sm font-semibold">Compañías</h2>
          <ul className="mt-2 text-sm">
            {user?.companies.length ? (
              user.companies.map((c) => <li key={c.id}>{c.name}</li>)
            ) : (
              <li className="text-jge-charcoal/70">
                {user?.role.slug === 'administrador' ? 'Acceso a todas las compañías.' : 'Sin compañías asignadas.'}
              </li>
            )}
          </ul>
        </div>
        <div className="rounded-lg border border-line bg-surface p-4">
          <h2 className="text-sm font-semibold">Sucursales</h2>
          <ul className="mt-2 text-sm">
            {user?.branches.length ? (
              user.branches.map((b) => <li key={b.id}>{b.name}</li>)
            ) : (
              <li className="text-jge-charcoal/70">Todas las de tus compañías.</li>
            )}
          </ul>
        </div>
      </div>
    </section>
  )
}
