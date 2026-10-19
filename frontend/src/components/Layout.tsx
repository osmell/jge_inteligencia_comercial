import { useState } from 'react'
import { NavLink, Outlet } from 'react-router-dom'
import { useAuth } from '../auth/AuthContext'
import { NAV_ITEMS } from './navigation'

function Brand() {
  return (
    <div className="flex items-center gap-2 px-4 py-4">
      <span className="rounded bg-white px-2 py-1 text-lg font-bold tracking-tight text-jge-blue">JGE</span>
      <span className="text-sm font-medium leading-tight text-white">Inteligencia Comercial</span>
    </div>
  )
}

export default function Layout() {
  const { user, logout, can } = useAuth()
  const [open, setOpen] = useState(false)
  const items = NAV_ITEMS.filter((i) => can(i.permission))

  const nav = (
    <nav aria-label="Principal" className="flex-1 space-y-1 px-2">
      {items.map((item) => (
        <NavLink
          key={item.to}
          to={item.to}
          end={item.to === '/'}
          onClick={() => setOpen(false)}
          className={({ isActive }) =>
            `block rounded-md px-3 py-2 text-sm font-medium ${
              isActive ? 'bg-white text-jge-blue' : 'text-white/90 hover:bg-jge-blue-dark'
            }`
          }
        >
          {item.label}
        </NavLink>
      ))}
    </nav>
  )

  return (
    <div className="flex h-full">
      <aside className="hidden w-64 shrink-0 flex-col bg-jge-blue md:flex">
        <Brand />
        {nav}
        <div className="h-1 bg-jge-red" aria-hidden="true" />
      </aside>

      {open && (
        <div className="fixed inset-0 z-40 md:hidden" role="dialog" aria-modal="true" aria-label="Menú">
          <button className="absolute inset-0 bg-black/40" aria-label="Cerrar menú" onClick={() => setOpen(false)} />
          <aside className="relative flex h-full w-64 flex-col bg-jge-blue">
            <Brand />
            {nav}
          </aside>
        </div>
      )}

      <div className="flex min-w-0 flex-1 flex-col">
        <header className="flex items-center justify-between border-b border-line bg-surface px-4 py-3">
          <button
            className="rounded-md border border-line px-3 py-1.5 text-sm md:hidden"
            aria-label="Abrir menú"
            aria-expanded={open}
            onClick={() => setOpen(true)}
          >
            Menú
          </button>
          <div className="ml-auto flex items-center gap-4">
            <div className="text-right text-sm leading-tight">
              <p className="font-medium">{user?.name}</p>
              <p className="text-xs text-jge-charcoal/70">{user?.role.name}</p>
            </div>
            <button
              onClick={() => void logout()}
              className="rounded-md border border-jge-blue px-3 py-1.5 text-sm font-medium text-jge-blue hover:bg-jge-blue hover:text-white"
            >
              Cerrar sesión
            </button>
          </div>
        </header>
        <main className="flex-1 overflow-y-auto p-4 md:p-6">
          <Outlet />
        </main>
      </div>
    </div>
  )
}
