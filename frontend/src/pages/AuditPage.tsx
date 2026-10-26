import { ChevronLeft, ChevronRight, Filter, ScrollText } from 'lucide-react'
import { useState, type FormEvent } from 'react'
import { useLoad } from '../lib/useLoad'
import type { AuditLog, Page } from '../lib/types'
import { Button, DataTable, EmptyRow, FormError, PageHeader, TableSkeleton, TextField, td, th, tr, Th } from '../components/ui'

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
      <PageHeader title="Auditoría" description="Registro de accesos y cambios. Los registros son de solo lectura." />
      <form onSubmit={onSearch} className="mb-4 flex flex-wrap items-end gap-3" role="search">
        <div className="w-full sm:w-56"><TextField id="audit-event" label="Evento" placeholder="p. ej. auth.login" value={event} onChange={(e) => setEvent(e.target.value)} /></div>
        <div className="w-full sm:w-56"><TextField id="audit-user" label="Usuario" value={username} onChange={(e) => setUsername(e.target.value)} /></div>
        <Button type="submit" variant="secondary" icon={<Filter className="h-4 w-4" aria-hidden="true" />}>Filtrar</Button>
      </form>

      <FormError message={logs.error} />
      <DataTable label="Registros de auditoría">
        <thead><tr><th className={th}>Fecha</th><th className={th}>Usuario</th><Th info={{ text: 'Acción que quedó registrada, por ejemplo crear, cambiar o exportar. Las contraseñas y credenciales nunca se guardan; solo se anota que cambiaron.' }}>Evento</Th><Th info={{ text: 'Tipo y número del registro sobre el que se hizo la acción.' }}>Registro</Th><Th info={{ text: 'Dirección de red desde la que se hizo la acción.' }}>IP</Th><th className={th}>Detalle</th></tr></thead>
        <tbody>
          {logs.loading && !page && <TableSkeleton cols={6} />}
          {(page?.data ?? []).map((l) => (
            <tr key={l.id} className={tr}>
              <td className={`${td} tabular whitespace-nowrap`}>{new Date(l.created_at).toLocaleString('es')}</td>
              <td className={`${td} font-mono text-[13px]`}>{l.username ?? '—'}</td>
              <td className={td}><span className="rounded bg-jge-blue-50 px-1.5 py-0.5 font-mono text-[12px] text-jge-blue">{l.event}</span></td>
              <td className={td}>{l.auditable_type ? `${l.auditable_type.split('\\').pop()} #${l.auditable_id}` : '—'}</td>
              <td className={`${td} tabular font-mono text-[13px]`}>{l.ip_address ?? '—'}</td>
              <td className={td}>
                {l.old_values || l.new_values ? (
                  <details>
                    <summary className="cursor-pointer text-sm font-medium text-jge-blue">Ver cambios</summary>
                    <pre className="mt-2 max-w-xs overflow-x-auto rounded-lg bg-surface-muted p-3 font-mono text-xs">{JSON.stringify({ antes: l.old_values, despues: l.new_values }, null, 2)}</pre>
                  </details>
                ) : '—'}
              </td>
            </tr>
          ))}
          {!logs.loading && page?.data.length === 0 && (
            <EmptyRow cols={6}><ScrollText className="mx-auto mb-2 h-6 w-6 text-ink-subtle" aria-hidden="true" />No hay registros para los filtros indicados.</EmptyRow>
          )}
        </tbody>
      </DataTable>

      {page && (
        <nav className="mt-3 flex flex-wrap items-center justify-between gap-2 text-sm" aria-label="Paginación">
          <span className="tabular text-ink-muted">Página {page.current_page} de {page.last_page} · {page.total} registros</span>
          <div className="flex gap-2">
            <Button variant="secondary" small icon={<ChevronLeft className="h-4 w-4" aria-hidden="true" />} disabled={page.current_page <= 1} onClick={() => setFilters({ ...filters, page: filters.page - 1 })}>Anterior</Button>
            <Button variant="secondary" small disabled={page.current_page >= page.last_page} onClick={() => setFilters({ ...filters, page: filters.page + 1 })}>
              Siguiente<ChevronRight className="h-4 w-4" aria-hidden="true" />
            </Button>
          </div>
        </nav>
      )}
    </section>
  )
}
