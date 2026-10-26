import { CornerDownLeft } from 'lucide-react'
import { useEffect, useMemo, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useAuth } from '../auth/AuthContext'
import { NAV_GROUPS } from './navigation'

/** Minúsculas y sin acentos, para que «cotizacion» encuentre «Cotizaciones». */
const plain = (s: string) => s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()

/** ¿La persona está escribiendo en un campo? Entonces «/» es un carácter más y no abre la barra. */
function typing(target: EventTarget | null): boolean {
  const el = target as HTMLElement | null
  return !!el && (['INPUT', 'TEXTAREA', 'SELECT'].includes(el.tagName) || el.isContentEditable)
}

/**
 * Barra de comandos del encabezado: «/» la enfoca desde cualquier pantalla, se escribe el nombre de una pantalla (con o sin la
 * barra inicial, p. ej. «/ventas») y Enter va a ella. Solo ofrece lo que la persona puede ver.
 */
export default function CommandBar() {
  const { canSee } = useAuth()
  const navigate = useNavigate()
  const input = useRef<HTMLInputElement>(null)
  const [text, setText] = useState('')
  const [open, setOpen] = useState(false)
  const [active, setActive] = useState(0)

  const items = useMemo(() => NAV_GROUPS.flatMap((g) => g.items.filter(canSee).map((i) => ({ ...i, group: g.title }))), [canSee])
  const query = plain(text.trim().replace(/^\/+/, ''))
  const results = useMemo(() => {
    const found = items.filter((i) => !query || plain(i.title ?? i.label).includes(query) || plain(i.label).includes(query) || plain(i.to).includes(query))
    // Primero las que empiezan por lo escrito.
    return found.sort((a, b) => Number(plain(b.title ?? b.label).startsWith(query)) - Number(plain(a.title ?? a.label).startsWith(query)))
  }, [items, query])

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if (e.key === '/' && !e.ctrlKey && !e.metaKey && !e.altKey && !typing(e.target)) {
        e.preventDefault()
        input.current?.focus()
      }
    }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [])

  function go(to: string) {
    setText('')
    setOpen(false)
    input.current?.blur()
    navigate(to)
  }

  function onKeyDown(e: React.KeyboardEvent) {
    if (e.key === 'Escape') {
      setText('')
      setOpen(false)
      input.current?.blur()
    } else if (e.key === 'ArrowDown') {
      e.preventDefault()
      setOpen(true)
      setActive((a) => Math.min(a + 1, results.length - 1))
    } else if (e.key === 'ArrowUp') {
      e.preventDefault()
      setActive((a) => Math.max(a - 1, 0))
    } else if (e.key === 'Enter' && results[active]) {
      e.preventDefault()
      go(results[active].to)
    }
  }

  const listId = 'command-results'

  return (
    <div className="relative min-w-0 flex-1 md:max-w-md">
      <label htmlFor="command-bar" className="sr-only">Ir a una pantalla (atajo: tecla /)</label>
      <span className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 font-mono text-sm text-ink-subtle" aria-hidden="true">/</span>
      <input
        ref={input}
        id="command-bar"
        type="text"
        role="combobox"
        aria-expanded={open}
        aria-controls={listId}
        aria-autocomplete="list"
        aria-activedescendant={open && results[active] ? `command-${active}` : undefined}
        autoComplete="off"
        spellCheck={false}
        value={text}
        placeholder="Ir a… (presiona / para buscar)"
        onChange={(e) => { setText(e.target.value); setActive(0); setOpen(true) }}
        onFocus={() => setOpen(true)}
        onBlur={() => setOpen(false)}
        onKeyDown={onKeyDown}
        className="min-h-10 w-full rounded-lg border border-line-strong bg-surface-muted pl-7 pr-3 font-mono text-sm text-ink placeholder:font-sans placeholder:text-ink-subtle focus:border-jge-blue focus:bg-surface focus:outline-none focus:ring-2 focus:ring-jge-blue/20"
      />
      {open && (
        <ul id={listId} role="listbox" aria-label="Pantallas" className="absolute left-0 right-0 z-50 mt-1 max-h-80 overflow-y-auto rounded-xl border border-line bg-surface py-1 shadow-[var(--shadow-pop)]">
          {results.length === 0 && <li className="px-4 py-3 text-sm text-ink-muted" role="option" aria-selected="false">No hay pantallas que coincidan.</li>}
          {results.map((r, i) => (
            // onMouseDown (y no onClick) para que el clic ocurra antes de que el campo pierda el foco.
            <li key={r.to} id={`command-${i}`} role="option" aria-selected={i === active} onMouseDown={(e) => { e.preventDefault(); go(r.to) }} onMouseEnter={() => setActive(i)}
              className={`flex cursor-pointer items-center gap-3 px-4 py-2 text-sm ${i === active ? 'bg-jge-blue-50 text-ink' : 'text-ink'}`}>
              <r.icon className="h-4 w-4 shrink-0 text-jge-blue" aria-hidden="true" />
              <span className="flex-1 truncate">{r.title ?? r.label}</span>
              <span className="hidden shrink-0 text-xs text-ink-subtle sm:inline">{r.group}</span>
              {i === active && <CornerDownLeft className="h-3.5 w-3.5 shrink-0 text-ink-subtle" aria-hidden="true" />}
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
