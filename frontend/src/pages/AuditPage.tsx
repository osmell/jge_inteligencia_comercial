import { useState, type FormEvent } from 'react'
import { useLoad } from '../lib/useLoad'
import type { AuditLog, Page } from '../lib/types'
import { DataTable, FormError, PageHeader, TextField, td, th } from '../components/ui'

export default function AuditPage() {
  const [event, setEvent] = useState('')
  const [username, setUsername] = useState('')
  const [filters, setFilters] = useState({ event: '', username: '', page: 1 })

  const query = new URLSearchParams({ page: String(filters.page) })
  if (filters.event) query.set('event', filters.event)
  if (filters.username) query.set('username', filters.username)
  const logs = useLoad<Page<AuditLog>>(`/admin/audit-logs?${query}`)

  function onSearch(e: FormEvent) {
    e.preventDefault()
    setFilters({ event: event.trim(), username: username.trim(), page: 1 })
  }

  const page = logs.data

  return (
    <section>
      <PageHeader title="Auditoría" />
      <form onSubmit={onSearch} className="mb-4 flex flex-wrap items-end gap-3" role="search">
        <div className="w-56"><TextField id="audit-event" label="Evento" placeholder="p. ej. auth.login" value={event} onChange={(e) => setEvent(e.target.value)} /></div>
        <div className="w-56"><TextField id="audit-user" label="Usuario" value={username} onChange={(e) => setUsername(e.target.value)} /></div>
        <button type="submit" className="rounded-md bg-jge-blue px-3 py-2 text-sm font-semibold text-white hover:bg-jge-blue-dark">Filtrar</button>
      </form>

      <FormError message={logs.error} />
      <DataTable label="Registros de auditoría">
        <thead><tr><th className={th}>Fecha</th><th className={th}>Usuario</th><th className={th}>Evento</th><th className={th}>Registro</th><th className={th}>IP</th><th className={th}>Detalle</th></tr></thead>
        <tbody>
          {(page?.data ?? []).map((l) => (
            <tr key={l.id}>
              <td className={td}>{new Date(l.created_at).toLocaleString('es')}</td>
              <td className={td}>{l.username ?? '—'}</td>
              <td className={td}>{l.event}</td>
              <td className={td}>{l.auditable_type ? `${l.auditable_type.split('\\').pop()} #${l.auditable_id}` : '—'}</td>
              <td className={td}>{l.ip_address ?? '—'}</td>
              <td className={td}>
                {l.old_values || l.new_values ? (
                  <details>
                    <summary className="cursor-pointer text-jge-blue underline">Ver cambios</summary>
                    <pre className="mt-1 max-w-xs overflow-x-auto rounded bg-canvas p-2 text-xs">{JSON.stringify({ antes: l.old_values, despues: l.new_values }, null, 2)}</pre>
                  </details>
                ) : '—'}
              </td>
            </tr>
          ))}
          {!logs.loading && page?.data.length === 0 && <tr><td className={td} colSpan={6}>No hay registros para los filtros indicados.</td></tr>}
        </tbody>
      </DataTable>

      {page && (
        <nav className="mt-3 flex items-center justify-between text-sm" aria-label="Paginación">
          <span>Página {page.current_page} de {page.last_page} · {page.total} registros</span>
          <div className="flex gap-2">
            <button className="rounded-md border border-line px-3 py-1.5 disabled:opacity-50" disabled={page.current_page <= 1} onClick={() => setFilters({ ...filters, page: filters.page - 1 })}>Anterior</button>
            <button className="rounded-md border border-line px-3 py-1.5 disabled:opacity-50" disabled={page.current_page >= page.last_page} onClick={() => setFilters({ ...filters, page: filters.page + 1 })}>Siguiente</button>
          </div>
        </nav>
      )}
    </section>
  )
}
