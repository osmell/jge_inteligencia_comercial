import { Info as InfoIcon } from 'lucide-react'
import { useCallback, useEffect, useId, useLayoutEffect, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import { resolveInfo, type Info } from '../lib/glossary'

const GAP = 8
const MARGIN = 8

interface Placed { top: number; left: number }

/**
 * Una «i» que explica un dato; si es calculado, muestra también su fórmula.
 * El globo se dibuja en el `body` con posición fija y se recoloca dentro de la ventana, por eso no se recorta dentro de tablas con
 * desplazamiento ni de ventanas modales y no cambia el ancho ni el alto de la página. Se abre con el ratón, con el teclado
 * (Tab) y con un toque; se cierra con Escape, al salir o al desplazarse.
 */
export function InfoTip({ info, label }: { info: Info; label?: string }) {
  const { text, formula } = resolveInfo(info)
  const id = useId()
  const button = useRef<HTMLButtonElement>(null)
  const tip = useRef<HTMLDivElement>(null)
  const [open, setOpen] = useState(false)
  const [placed, setPlaced] = useState<Placed | null>(null)

  const close = useCallback(() => { setOpen(false); setPlaced(null) }, [])

  useLayoutEffect(() => {
    if (!open || !button.current || !tip.current) return
    const b = button.current.getBoundingClientRect()
    const t = tip.current.getBoundingClientRect()
    const left = Math.min(Math.max(b.left + b.width / 2 - t.width / 2, MARGIN), Math.max(window.innerWidth - t.width - MARGIN, MARGIN))
    const below = b.bottom + GAP
    const top = below + t.height + MARGIN <= window.innerHeight ? below : Math.max(b.top - GAP - t.height, MARGIN)
    setPlaced({ top, left })
  }, [open, text, formula])

  useEffect(() => {
    if (!open) return
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') close() }
    const onPointer = (e: PointerEvent) => { if (e.target instanceof Node && !button.current?.contains(e.target)) close() }
    window.addEventListener('keydown', onKey)
    window.addEventListener('pointerdown', onPointer)
    window.addEventListener('scroll', close, true)
    window.addEventListener('resize', close)
    return () => {
      window.removeEventListener('keydown', onKey)
      window.removeEventListener('pointerdown', onPointer)
      window.removeEventListener('scroll', close, true)
      window.removeEventListener('resize', close)
    }
  }, [open, close])

  return (
    <>
      <button
        ref={button}
        type="button"
        aria-label={label ? `Qué significa: ${label}` : 'Qué significa este dato'}
        aria-describedby={open ? id : undefined}
        aria-expanded={open}
        onMouseEnter={() => setOpen(true)}
        onMouseLeave={() => { if (document.activeElement !== button.current) close() }}
        onFocus={() => setOpen(true)}
        onBlur={close}
        onClick={(e) => { e.preventDefault(); e.stopPropagation(); setOpen(true) }}
        className="relative inline-flex h-4 w-4 shrink-0 cursor-help items-center justify-center rounded-full align-middle text-ink-subtle transition-colors after:absolute after:-inset-1.5 after:content-[''] hover:text-jge-blue focus-visible:text-jge-blue print:hidden"
      >
        <InfoIcon className="h-3.5 w-3.5" aria-hidden="true" />
      </button>
      {open && createPortal(
        <div
          ref={tip}
          id={id}
          role="tooltip"
          style={{ top: placed?.top ?? 0, left: placed?.left ?? 0, visibility: placed ? 'visible' : 'hidden' }}
          className="pointer-events-none fixed z-[70] max-w-[min(20rem,calc(100vw-1rem))] rounded-lg bg-ink px-3 py-2 text-left text-xs font-normal normal-case leading-relaxed tracking-normal text-white shadow-lg print:hidden"
        >
          <p>{text}</p>
          {formula && (
            <p className="mt-1.5 border-t border-white/25 pt-1.5">
              <span className="font-semibold">Fórmula: </span>
              <span className="tabular">{formula}</span>
            </p>
          )}
        </div>,
        document.body,
      )}
    </>
  )
}
