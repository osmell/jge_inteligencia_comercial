import { LogOut, Menu, X } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { NavLink, Outlet, useLocation } from 'react-router-dom'
import { useAuth } from '../auth/AuthContext'
import { BrandMark } from './Brand'
import { NAV_GROUPS, NAV_ITEMS } from './navigation'

function initials(name: string) {
  return name.split(/\s+/).filter(Boolean).slice(0, 2).map((p) => p[0]!.toUpperCase()).join('')
}

export default function Layout() {
  const { user, logout, can } = useAuth()
  const [open, setOpen] = useState(false)
  const { pathname } = useLocation()
  const menuButton = useRef<HTMLButtonElement>(null)
  const current = NAV_ITEMS.find((i) => (i.to === '/' ? pathname === '/' : pathname.startsWith(i.to)))

  useEffect(() => {
    if (!open) return
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        setOpen(false)
        menuButton.current?.focus()
      }
    }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [open])

  const nav = (
    <nav aria-label="Principal" className="flex-1 space-y-6 overflow-y-auto px-3 py-4">
      {NAV_GROUPS.map((group) => {
        const items = group.items.filter((i) => can(i.permission))
        if (items.length === 0) return null
        return (
          <div key={group.title}>
            <p className="mb-1.5 px-3 text-[11px] font-semibold uppercase tracking-wider text-white/60">{group.title}</p>
            <ul className="space-y-0.5">
              {items.map((item) => (
                <li key={item.to}>
                  <NavLink
                    to={item.to}
                    end={item.to === '/'}
                    onClick={() => setOpen(false)}
                    className={({ isActive }) =>
                      `group relative flex min-h-11 items-center gap-3 rounded-lg px-3 text-sm font-medium transition-colors duration-150 ${
                        isActive ? 'bg-white/12 text-white' : 'text-white/80 hover:bg-white/8 hover:text-white'
                      }`
                    }
                  >
                    {({ isActive }) => (
                      <>
                        {isActive && <span className="absolute left-0 top-2 h-7 w-1 rounded-r bg-jge-red" aria-hidden="true" />}
                        <item.icon className="h-[18px] w-[18px] shrink-0" aria-hidden="true" />
                        {item.label}
                      </>
                    )}
                  </NavLink>
                </li>
              ))}
            </ul>
          </div>
        )
      })}
    </nav>
  )

  const brand = (
    <div className="flex items-center gap-3 px-5 py-5">
      <BrandMark />
      <div className="leading-tight">
        <p className="text-sm font-semibold text-white">JGE</p>
        <p className="text-xs text-white/70">Inteligencia Comercial</p>
      </div>
    </div>
  )

  return (
    <div className="flex h-full">
      <aside className="on-dark hidden w-64 shrink-0 flex-col bg-jge-navy md:flex">
        {brand}
        {nav}
        <p className="px-5 py-3 text-[11px] text-white/50">JGE Soluciones Integrales</p>
      </aside>

      {open && (
        <div className="fixed inset-0 z-40 md:hidden" role="dialog" aria-modal="true" aria-label="Menú de navegación">
          <button className="absolute inset-0 bg-ink/50" aria-label="Cerrar menú" onClick={() => setOpen(false)} />
          <aside className="on-dark relative flex h-full w-72 max-w-[85%] flex-col bg-jge-navy">
            <button
              className="absolute right-3 top-4 flex h-9 w-9 cursor-pointer items-center justify-center rounded-lg text-white/80 hover:bg-white/10"
              aria-label="Cerrar menú"
              onClick={() => setOpen(false)}
            >
              <X className="h-5 w-5" aria-hidden="true" />
            </button>
            {brand}
            {nav}
          </aside>
        </div>
      )}

      <div className="flex min-w-0 flex-1 flex-col">
        <header className="flex h-16 shrink-0 items-center gap-3 border-b border-line bg-surface px-4 md:px-6">
          <button
            ref={menuButton}
            className="flex h-10 w-10 cursor-pointer items-center justify-center rounded-lg border border-line-strong text-ink md:hidden"
            aria-label="Abrir menú"
            aria-expanded={open}
            onClick={() => setOpen(true)}
          >
            <Menu className="h-5 w-5" aria-hidden="true" />
          </button>
          <p className="truncate text-sm font-medium text-ink-muted">{current?.label ?? ''}</p>

          <div className="ml-auto flex items-center gap-3">
            <div className="flex items-center gap-2.5">
              <span className="flex h-9 w-9 items-center justify-center rounded-full bg-jge-blue-50 text-xs font-semibold text-jge-blue" aria-hidden="true">
                {initials(user?.name ?? '')}
              </span>
              <div className="hidden text-right leading-tight sm:block">
                <p className="text-sm font-medium text-ink">{user?.name}</p>
                <p className="text-xs text-ink-muted">{user?.role.name}</p>
              </div>
            </div>
            <button
              onClick={() => void logout()}
              aria-label="Cerrar sesión"
              className="inline-flex min-h-10 cursor-pointer items-center gap-2 rounded-lg border border-line-strong px-3 text-sm font-medium text-ink transition-colors hover:bg-surface-muted"
            >
              <LogOut className="h-4 w-4" aria-hidden="true" />
              <span className="hidden sm:inline" aria-hidden="true">Cerrar sesión</span>
            </button>
          </div>
        </header>
        <main className="flex-1 overflow-y-auto p-4 md:p-8">
          <div className="mx-auto max-w-6xl">
            <Outlet />
          </div>
        </main>
      </div>
    </div>
  )
}
