import { Bell } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { api } from '../lib/api'
import { formatDateTime } from '../lib/format'
import type { Notice, NoticeSummary } from '../lib/notifications'
import { SEVERITY_TEXT } from '../lib/notifications'
import { useLoad } from '../lib/useLoad'

/** Campana de avisos internos: contador de nuevos (se actualiza solo cada minuto) y los más recientes. */
export default function NotificationBell() {
  const navigate = useNavigate()
  const summary = useLoad<{ data: NoticeSummary }>('/notifications/summary', { pollEvery: 60000, pollWhile: () => true })
  const [open, setOpen] = useState(false)
  const box = useRef<HTMLDivElement>(null)
  const data = summary.data?.data
  const unread = data?.unread ?? 0

  useEffect(() => {
    if (!open) return
    const onDown = (e: MouseEvent) => {
      if (box.current && !box.current.contains(e.target as Node)) setOpen(false)
    }
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') setOpen(false)
    }
    document.addEventListener('mousedown', onDown)
    document.addEventListener('keydown', onKey)
    return () => {
      document.removeEventListener('mousedown', onDown)
      document.removeEventListener('keydown', onKey)
    }
  }, [open])

  async function openNotice(n: Notice) {
    setOpen(false)
    if (n.status === 'new') await api(`/notifications/${n.id}/seen`, { method: 'POST' }).catch(() => undefined)
    summary.reload()
    if (n.url) navigate(n.url)
  }

  async function markAll() {
    await api('/notifications/seen-all', { method: 'POST' }).catch(() => undefined)
    summary.reload()
  }

  return (
    <div ref={box} className="relative">
      <button
        type="button"
        aria-label={unread > 0 ? `Avisos: ${unread} nuevos` : 'Avisos'}
        aria-expanded={open}
        aria-haspopup="true"
        onClick={() => setOpen(!open)}
        className="relative flex h-10 w-10 cursor-pointer items-center justify-center rounded-lg border border-line-strong text-ink transition-colors hover:bg-surface-muted"
      >
        <Bell className="h-5 w-5" aria-hidden="true" />
        {unread > 0 && (
          <span className="tabular absolute -right-1.5 -top-1.5 flex min-w-5 items-center justify-center rounded-full bg-jge-red px-1 text-[11px] font-semibold leading-5 text-white" aria-hidden="true">{unread > 99 ? '99+' : unread}</span>
        )}
      </button>
      {open && (
        <div role="region" aria-label="Avisos recientes" className="absolute right-0 z-50 mt-2 w-[min(22rem,calc(100vw-2rem))] rounded-xl border border-line bg-surface shadow-[var(--shadow-pop)]">
          <div className="flex items-center justify-between border-b border-line px-4 py-2.5">
            <p className="text-sm font-semibold text-ink">Avisos</p>
            {unread > 0 && <button type="button" className="cursor-pointer text-xs text-jge-blue hover:underline" onClick={() => void markAll()}>Marcar todo como visto</button>}
          </div>
          {data?.paused && <p className="border-b border-line bg-surface-muted px-4 py-2 text-xs text-ink-muted">Tienes los avisos en pausa: no recibirás nuevos hasta que los reanudes.</p>}
          {(data?.latest ?? []).length === 0 ? (
            <p className="px-4 py-6 text-center text-sm text-ink-muted">No tienes avisos.</p>
          ) : (
            <ul className="max-h-96 divide-y divide-line overflow-y-auto">
              {(data?.latest ?? []).map((n) => (
                <li key={n.id}>
                  <button type="button" onClick={() => void openNotice(n)} className="block w-full cursor-pointer px-4 py-3 text-left hover:bg-surface-muted">
                    <p className="flex items-start gap-2 text-sm">
                      {n.status === 'new' && <span className="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-jge-red" aria-label="Nuevo" />}
                      <span className={n.status === 'new' ? 'font-semibold text-ink' : 'text-ink'}>{n.title}</span>
                    </p>
                    {n.body && <p className="mt-0.5 text-xs text-ink-muted">{n.body}</p>}
                    <p className="mt-0.5 text-[11px] text-ink-subtle">{n.severity !== 'info' && <>{SEVERITY_TEXT[n.severity]} · </>}{formatDateTime(n.created_at)}</p>
                  </button>
                </li>
              ))}
            </ul>
          )}
          <div className="border-t border-line px-4 py-2.5 text-center">
            <Link to="/notificaciones" onClick={() => setOpen(false)} className="text-sm text-jge-blue hover:underline">Ver todos los avisos y preferencias</Link>
          </div>
        </div>
      )}
    </div>
  )
}
