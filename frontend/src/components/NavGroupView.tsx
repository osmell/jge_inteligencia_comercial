import { ChevronDown } from 'lucide-react'
import { useEffect, useId, useState, type ReactNode } from 'react'
import { useLocation } from 'react-router-dom'
import type { NavGroup, NavItem } from './navigation'

const STORAGE_PREFIX = 'nav-open:'

/** Lo guardado es solo una comodidad de cada navegador: si no se puede leer o escribir, el menú funciona igual. */
function readOpen(title: string, fallback: boolean): boolean {
  try {
    const stored = localStorage.getItem(STORAGE_PREFIX + title)
    return stored === null ? fallback : stored === '1'
  } catch {
    return fallback
  }
}

function writeOpen(title: string, open: boolean) {
  try {
    localStorage.setItem(STORAGE_PREFIX + title, open ? '1' : '0')
  } catch {
    // sin almacenamiento disponible: no se recuerda
  }
}

const heading = 'px-3 text-[11px] font-semibold uppercase tracking-wider text-white/60'

/**
 * Un grupo del menú. Un grupo normal es un título con su lista siempre visible; uno «desplegable» es un botón que abre y cierra su lista,
 * se abre solo cuando se está dentro de alguna de sus pantallas y recuerda en el navegador si la persona lo dejó abierto.
 */
export default function NavGroupView({ group, items, renderItem }: { group: NavGroup; items: NavItem[]; renderItem: (item: NavItem) => ReactNode }) {
  const { pathname } = useLocation()
  const listId = useId()
  const inside = items.some((i) => (i.to === '/' ? pathname === '/' : [i.to, ...(i.also ?? [])].some((p) => pathname === p || pathname.startsWith(`${p}/`))))
  const [remembered, setRemembered] = useState(() => (group.collapsible ? readOpen(group.title, group.defaultOpen ?? false) : true))
  const open = !group.collapsible || remembered || inside

  // Al entrar a una pantalla del grupo queda abierto, también la próxima vez.
  useEffect(() => {
    if (group.collapsible && inside) {
      // eslint-disable-next-line react-hooks/set-state-in-effect
      setRemembered(true)
      writeOpen(group.title, true)
    }
  }, [group.collapsible, group.title, inside])

  if (!group.collapsible) {
    return (
      <div>
        <p className={`mb-1.5 ${heading}`}>{group.title}</p>
        <ul className="space-y-0.5">{items.map((item) => <li key={item.to}>{renderItem(item)}</li>)}</ul>
      </div>
    )
  }

  return (
    <div>
      <button
        type="button"
        aria-expanded={open}
        aria-controls={listId}
        onClick={() => {
          setRemembered(!open)
          writeOpen(group.title, !open)
        }}
        className={`${heading} mb-1.5 flex min-h-9 w-full cursor-pointer items-center justify-between rounded-lg transition-colors hover:bg-white/8 hover:text-white`}
      >
        {group.title}
        <ChevronDown className={`h-4 w-4 transition-transform duration-150 ${open ? 'rotate-180' : ''}`} aria-hidden="true" />
      </button>
      <ul id={listId} aria-label={group.title} hidden={!open} className="space-y-0.5">{items.map((item) => <li key={item.to}>{renderItem(item)}</li>)}</ul>
    </div>
  )
}
