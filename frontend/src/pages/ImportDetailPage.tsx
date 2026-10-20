import { ArrowLeft, Ban, CheckCheck, Download } from 'lucide-react'
import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { api, ApiError, download } from '../lib/api'
import { formatBytes, formatDateTime, formatMoney } from '../lib/format'
import { POLL_MS } from '../lib/polling'
import type { ImportBatch, ImportErrorRow, Page } from '../lib/types'
import { useLoad } from '../lib/useLoad'
import { ImportStatusBadge } from '../components/ImportStatusBadge'
import { IN_PROGRESS } from '../lib/importStatus'
import { useToast } from '../components/toast'
import { Alert, Button, Card, ConfirmDialog, DataTable, EmptyRow, FormError, PageHeader, SelectField, TableSkeleton, td, th, tr } from '../components/ui'

const STATUS_MESSAGE: Record<string, { tone: 'info' | 'success' | 'danger' | 'warning'; text: string }> = {
  pending: { tone: 'info', text: 'El archivo está en cola para validarse. Si lleva más de un minuto así, el procesador de importaciones podría estar detenido (en desarrollo: docker compose up -d queue).' },
  validating: { tone: 'info', text: 'Validando el archivo…' },
  validated: { tone: 'success', text: 'El archivo es válido. Revisa el resumen y, si todo está bien, confirma para importarlo.' },
  rejected: { tone: 'danger', text: 'El archivo tiene errores y no se puede importar. Corrígelos en el Excel y súbelo de nuevo.' },
  importing: { tone: 'info', text: 'Importando los datos…' },
  imported: { tone: 'success', text: 'Importación completada.' },
  discarded: { tone: 'info', text: 'Este lote fue descartado; el archivo se eliminó.' },
}

function Stat({ label, value, hint }: { label: string; value: string | number; hint?: string }) {
  return (
    <Card className="p-4">
      <p className="text-sm text-ink-muted">{label}</p>
      <p className="tabular text-2xl font-semibold tracking-tight">{value}</p>
      {hint && <p className="mt-0.5 text-xs text-ink-muted">{hint}</p>}
    </Card>
  )
}

function ErrorsSection({ batch }: { batch: ImportBatch }) {
  const [severity, setSeverity] = useState('')
  const [page, setPage] = useState(1)
  const [csvError, setCsvError] = useState<string | null>(null)
  const query = new URLSearchParams({ page: String(page) })
  if (severity) query.set('severity', severity)
  const errors = useLoad<Page<ImportErrorRow>>(`/admin/imports/${batch.id}/errors?${query}`)
  const rows = errors.data?.data ?? []
  const totalReported = batch.errors_total + batch.warnings_total

  async function csv() {
    try {
      await download(`/admin/imports/${batch.id}/errors.csv`, `errores-importacion-${batch.id}.csv`)
    } catch (e) {
      setCsvError(e instanceof Error ? e.message : 'No se pudo descargar el CSV.')
    }
  }

  return (
    <section>
      <PageHeader
        level={2}
        title="Errores y advertencias"
        description="Los errores impiden importar; las advertencias son avisos que conviene revisar."
        action={<Button variant="secondary" small icon={<Download className="h-4 w-4" aria-hidden="true" />} onClick={() => void csv()}>Descargar CSV</Button>}
      />
      <div className="mb-3 w-full max-w-[14rem]">
        <SelectField id="severity" label="Mostrar" value={severity} onChange={(e) => { setSeverity(e.target.value); setPage(1) }}>
          <option value="">Todos</option>
          <option value="error">Solo errores</option>
          <option value="warning">Solo advertencias</option>
        </SelectField>
      </div>
      <FormError message={errors.error ?? csvError} />
      <DataTable label="Errores de la importación">
        <thead><tr><th className={th}>Fila</th><th className={th}>Columna</th><th className={th}>Tipo</th><th className={th}>Mensaje</th><th className={th}>Valor</th></tr></thead>
        <tbody>
          {errors.loading && !errors.data && <TableSkeleton cols={5} />}
          {rows.map((e) => (
            <tr key={e.id} className={tr}>
              <td className={`${td} tabular`}>{e.row_number ?? '—'}</td>
              <td className={td}>{e.column ?? '—'}</td>
              <td className={td}>
                <span className={`rounded-full border px-2 py-0.5 text-xs font-medium ${e.severity === 'error' ? 'border-danger/30 bg-danger-50 text-danger' : 'border-warning/40 bg-warning-50 text-warning'}`}>
                  {e.severity === 'error' ? 'Error' : 'Advertencia'}
                </span>
              </td>
              <td className={td}>{e.message}</td>
              <td className={`${td} max-w-[12rem] truncate font-mono text-[13px]`} title={e.value ?? ''}>{e.value ?? '—'}</td>
            </tr>
          ))}
          {!errors.loading && rows.length === 0 && <EmptyRow cols={5}>No hay registros para este filtro.</EmptyRow>}
        </tbody>
      </DataTable>
      {errors.data && errors.data.total < totalReported && !severity && (
        <p className="mt-2 text-xs text-ink-muted">Se muestran los primeros {errors.data.total} de {totalReported}. Corrige estos y vuelve a validar para ver el resto.</p>
      )}
      {errors.data && errors.data.last_page > 1 && (
        <nav className="mt-3 flex items-center justify-between text-sm" aria-label="Paginación de errores">
          <span className="tabular text-ink-muted">Página {errors.data.current_page} de {errors.data.last_page}</span>
          <div className="flex gap-2">
            <Button variant="secondary" small disabled={page <= 1} onClick={() => setPage(page - 1)}>Anterior</Button>
            <Button variant="secondary" small disabled={page >= errors.data.last_page} onClick={() => setPage(page + 1)}>Siguiente</Button>
          </div>
        </nav>
      )}
    </section>
  )
}

/** Archivos maestros: insertan los nuevos y actualizan los existentes (en lugar de importar documentos). */
const MASTER_LABEL: Record<string, { plural: string; description: string }> = {
  customers: { plural: 'clientes', description: 'El archivo maestro inserta los clientes nuevos y actualiza los existentes (compañía + código).' },
  products: { plural: 'productos', description: 'El archivo maestro inserta los productos nuevos y actualiza los existentes (compañía + código).' },
}

const isMaster = (type: string) => type in MASTER_LABEL

function MasterSummary({ batch }: { batch: ImportBatch }) {
  const c = batch.summary?.customers ?? batch.summary?.products
  if (!c) return null

  return (
    <section>
      <PageHeader level={2} title="Resumen" description={MASTER_LABEL[batch.type]?.description} />
      <div className="grid gap-4 sm:grid-cols-3">
        <Stat label="Nuevos" value={c.new} />
        <Stat label="Se actualizarán" value={c.updated} hint="Con lo que trae el archivo; una celda vacía borra ese dato" />
        <Stat label="Sin cambios" value={c.unchanged} />
      </div>
    </section>
  )
}

function SummarySection({ batch }: { batch: ImportBatch }) {
  const s = batch.summary
  if (s?.customers || s?.products) return <MasterSummary batch={batch} />
  if (!s?.totals) return null

  return (
    <section>
      <PageHeader level={2} title="Resumen" description={s.date_from ? `Ventas del ${s.date_from} al ${s.date_to}` : undefined} />
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <Stat label="Total neto" value={formatMoney(s.totals.net_total)} />
        <Stat label="Impuesto" value={formatMoney(s.totals.tax_total)} />
        <Stat label="Gran total" value={formatMoney(s.totals.grand_total)} />
        <Stat label="Costo" value={formatMoney(s.totals.cost_total)} hint="Costo unitario actual" />
      </div>

      {s.companies && s.companies.length > 0 && (
        <div className="mt-4">
          <DataTable label="Resumen por compañía">
            <thead><tr><th className={th}>Compañía</th><th className={th}>Documentos</th><th className={th}>Líneas</th><th className={`${th} text-right`}>Total neto</th></tr></thead>
            <tbody>
              {s.companies.map((c) => (
                <tr key={c.id} className={tr}>
                  <td className={`${td} font-medium`}>{c.name}</td>
                  <td className={`${td} tabular`}>{c.documents}</td>
                  <td className={`${td} tabular`}>{c.lines}</td>
                  <td className={`${td} tabular text-right`}>{formatMoney(c.net_total)}</td>
                </tr>
              ))}
            </tbody>
          </DataTable>
        </div>
      )}

      {((s.new_sellers?.length ?? 0) > 0 || (s.new_operators?.length ?? 0) > 0) && (
        <div className="mt-4">
          <Alert tone="info">
            Se darán de alta automáticamente
            {(s.new_sellers?.length ?? 0) > 0 && <> los vendedores: <strong>{s.new_sellers!.join(', ')}</strong></>}
            {(s.new_sellers?.length ?? 0) > 0 && (s.new_operators?.length ?? 0) > 0 && ' y'}
            {(s.new_operators?.length ?? 0) > 0 && <> los operadores: <strong>{s.new_operators!.join(', ')}</strong></>}.
          </Alert>
        </div>
      )}
    </section>
  )
}

function PreviewSection({ batch }: { batch: ImportBatch }) {
  const preview = batch.summary?.preview ?? []
  if (preview.length === 0) return null
  const columns = batch.summary?.preview_columns ?? Object.keys(preview[0].values)

  return (
    <section>
      <PageHeader level={2} title="Vista previa" description={`Primeras ${preview.length} filas del archivo, tal como se leyeron.`} />
      <DataTable label="Vista previa del archivo">
        <thead><tr><th className={th}>Fila</th>{columns.map((c) => <th key={c} className={th}>{c}</th>)}</tr></thead>
        <tbody>
          {preview.map((r) => (
            <tr key={r.row} className={tr}>
              <td className={`${td} tabular text-ink-muted`}>{r.row}</td>
              {columns.map((c) => <td key={c} className={`${td} whitespace-nowrap`}>{r.values[c] ?? '—'}</td>)}
            </tr>
          ))}
        </tbody>
      </DataTable>
    </section>
  )
}

export default function ImportDetailPage() {
  const { id } = useParams()
  const toast = useToast()
  const detail = useLoad<{ data: ImportBatch }>(`/admin/imports/${id}`, {
    pollEvery: POLL_MS,
    pollWhile: (d) => IN_PROGRESS.includes(d.data.status),
  })
  const [dialog, setDialog] = useState<'confirm' | 'discard' | null>(null)
  const [busy, setBusy] = useState(false)
  const [actionError, setActionError] = useState<string | null>(null)

  const batch = detail.data?.data

  async function act(kind: 'confirm' | 'discard') {
    setBusy(true)
    setActionError(null)
    try {
      await api(`/admin/imports/${id}/${kind}`, { method: 'POST' })
      toast.success(kind === 'confirm' ? 'Importación en curso.' : 'Lote descartado.')
      await detail.reload()
    } catch (e) {
      setActionError(e instanceof ApiError ? e.message : 'No se pudo completar la acción.')
    } finally {
      setBusy(false)
      setDialog(null)
    }
  }

  if (detail.error && !batch) {
    return (
      <section>
        <FormError message={detail.error} />
        <Link to="/importaciones" className="mt-3 inline-flex items-center gap-1 text-sm font-medium text-jge-blue"><ArrowLeft className="h-4 w-4" aria-hidden="true" />Volver a importaciones</Link>
      </section>
    )
  }
  if (!batch) return <div className="space-y-3" aria-busy="true"><div className="skeleton h-8 w-64" /><div className="skeleton h-24 w-full" /></div>

  const message = batch.status === 'failed' ? { tone: 'warning' as const, text: batch.failure_message ?? 'Falló el procesamiento.' } : STATUS_MESSAGE[batch.status]

  return (
    <section className="space-y-8">
      <div>
        <Link to="/importaciones" className="mb-2 inline-flex items-center gap-1 text-sm font-medium text-jge-blue"><ArrowLeft className="h-4 w-4" aria-hidden="true" />Importaciones</Link>
        <PageHeader
          title={batch.original_name}
          description={`${batch.type_label} · ${formatBytes(batch.file_size)} · subido el ${formatDateTime(batch.created_at)}${batch.user ? ` por ${batch.user.username}` : ''}`}
          action={
            <div className="flex flex-wrap items-center gap-2">
              <ImportStatusBadge status={batch.status} label={batch.status_label} />
              {batch.can_discard && <Button variant="secondary" icon={<Ban className="h-4 w-4" aria-hidden="true" />} onClick={() => setDialog('discard')}>Descartar</Button>}
              {batch.can_confirm && <Button icon={<CheckCheck className="h-4 w-4" aria-hidden="true" />} onClick={() => setDialog('confirm')}>Confirmar importación</Button>}
            </div>
          }
        />
        <div className="space-y-2">
          {message && <Alert tone={message.tone}>{message.text}</Alert>}
          <FormError message={actionError} />
        </div>
      </div>

      {!IN_PROGRESS.includes(batch.status) && batch.status !== 'discarded' && (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <Stat label="Filas leídas" value={batch.rows_total} hint={batch.summary?.rows_invalid ? `${batch.summary.rows_invalid} con errores` : undefined} />
          <Stat
            label={isMaster(batch.type) ? (batch.type === 'products' ? 'Productos' : 'Clientes') : 'Documentos'}
            value={batch.documents_total}
            hint={batch.status === 'rejected' ? undefined : `${batch.documents_new} nuevos · ${batch.documents_duplicate} ${isMaster(batch.type) ? 'sin cambios' : 'ya importados'}`}
          />
          <Stat label="Errores" value={batch.errors_total} />
          <Stat label="Advertencias" value={batch.warnings_total} />
        </div>
      )}

      {/* El resumen solo es fiable cuando no hay errores: con filas rechazadas sería parcial. */}
      {(batch.status === 'validated' || batch.status === 'imported') && <SummarySection batch={batch} />}
      {!IN_PROGRESS.includes(batch.status) && batch.status !== 'imported' && <PreviewSection batch={batch} />}
      {!IN_PROGRESS.includes(batch.status) && batch.errors_total + batch.warnings_total > 0 && <ErrorsSection key={batch.status} batch={batch} />}

      {dialog === 'confirm' && (
        <ConfirmDialog title="Confirmar importación" confirmLabel="Importar" busy={busy} onConfirm={() => void act('confirm')} onCancel={() => setDialog(null)}>
          {isMaster(batch.type) ? (
            <>
              <p>Se crearán <strong>{(batch.summary?.customers ?? batch.summary?.products)?.new ?? batch.documents_new}</strong> {MASTER_LABEL[batch.type].plural} y se actualizarán <strong>{(batch.summary?.customers ?? batch.summary?.products)?.updated ?? 0}</strong> con los datos del archivo.</p>
              <p className="mt-2">Si algo falla durante la importación no se escribe nada.</p>
            </>
          ) : (
            <>
              <p>Se importarán <strong>{batch.documents_new}</strong> documentos nuevos{batch.documents_duplicate > 0 && <> (los {batch.documents_duplicate} ya importados se omitirán)</>}.</p>
              <p className="mt-2">Si algo falla durante la importación no se escribe nada. Las ventas importadas no se pueden modificar ni borrar desde la aplicación.</p>
            </>
          )}
        </ConfirmDialog>
      )}
      {dialog === 'discard' && (
        <ConfirmDialog title="Descartar lote" confirmLabel="Descartar" danger busy={busy} onConfirm={() => void act('discard')} onCancel={() => setDialog(null)}>
          <p>Se descarta este lote y se elimina el archivo subido. No afecta a datos ya importados.</p>
        </ConfirmDialog>
      )}
    </section>
  )
}
