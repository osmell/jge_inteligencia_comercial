import { ChevronLeft, ChevronRight, Download, FileSpreadsheet, Upload } from 'lucide-react'
import { useRef, useState, type DragEvent, type FormEvent } from 'react'
import { useNavigate } from 'react-router-dom'
import { useAuth } from '../auth/AuthContext'
import { api, ApiError, download } from '../lib/api'
import { formatBytes, formatDateTime } from '../lib/format'
import { POLL_MS } from '../lib/polling'
import type { ImportBatch, ImportType, Page } from '../lib/types'
import { useLoad } from '../lib/useLoad'
import { ImportStatusBadge } from '../components/ImportStatusBadge'
import { IN_PROGRESS } from '../lib/importStatus'
import { Button, Card, DataTable, EmptyRow, FormError, PageHeader, SelectField, TableSkeleton, td, th, tr } from '../components/ui'

const MAX_MB = 20

export default function ImportsPage() {
  const navigate = useNavigate()
  const { user } = useAuth()
  const isAdmin = user?.role.slug === 'administrador'
  const [page, setPage] = useState(1)
  const types = useLoad<{ data: ImportType[] }>('/admin/imports/types')
  const history = useLoad<Page<ImportBatch>>(`/admin/imports?page=${page}`, {
    pollEvery: POLL_MS,
    pollWhile: (p) => p.data.some((b) => IN_PROGRESS.includes(b.status)),
  })

  const [type, setType] = useState('')
  const [file, setFile] = useState<File | null>(null)
  const [dragging, setDragging] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [uploading, setUploading] = useState(false)
  const input = useRef<HTMLInputElement>(null)

  const typeList = types.data?.data ?? []
  const selectedType = type || typeList[0]?.key || ''

  function pick(f: File | undefined | null) {
    setError(null)
    if (!f) return
    if (!f.name.toLowerCase().endsWith('.xlsx')) return setError('El archivo debe ser un Excel (.xlsx).')
    if (f.size > MAX_MB * 1024 * 1024) return setError(`El archivo supera el máximo de ${MAX_MB} MB.`)
    setFile(f)
  }

  function onDrop(e: DragEvent) {
    e.preventDefault()
    setDragging(false)
    pick(e.dataTransfer.files[0])
  }

  async function onSubmit(e: FormEvent) {
    e.preventDefault()
    if (!file) return
    setUploading(true)
    setError(null)
    try {
      const body = new FormData()
      body.append('type', selectedType)
      body.append('file', file)
      const r = await api<{ data: ImportBatch }>('/admin/imports', { method: 'POST', body })
      navigate(`/importaciones/${r.data.id}`)
    } catch (err) {
      setError(err instanceof ApiError ? (err.errors.file?.[0] ?? err.errors.type?.[0] ?? err.message) : 'No se pudo subir el archivo.')
    } finally {
      setUploading(false)
    }
  }

  async function template() {
    try {
      await download(`/admin/imports/templates/${selectedType}`, 'plantilla.xlsx')
    } catch (err) {
      setError(err instanceof Error ? err.message : 'No se pudo descargar la plantilla.')
    }
  }

  const batches = history.data?.data ?? []

  return (
    <section className="space-y-8">
      <PageHeader
        title="Importaciones"
        description="Sube un archivo de Excel: primero se valida sin escribir datos; tú revisas el resultado y decides si importar."
        action={
          <Button variant="secondary" icon={<Download className="h-4 w-4" aria-hidden="true" />} disabled={!selectedType} onClick={() => void template()}>
            Descargar plantilla
          </Button>
        }
      />

      <Card className="p-5">
        <form onSubmit={onSubmit} className="space-y-4" noValidate>
          <FormError message={error} />
          <div className="grid gap-4 sm:grid-cols-[14rem_1fr]">
            <SelectField id="import-type" label="Tipo de archivo" value={selectedType} onChange={(e) => setType(e.target.value)}>
              {typeList.map((t) => <option key={t.key} value={t.key}>{t.label}</option>)}
            </SelectField>

            <div>
              <span className="mb-1 block text-sm font-medium">Archivo de Excel (.xlsx)</span>
              <div
                onDragOver={(e) => { e.preventDefault(); setDragging(true) }}
                onDragLeave={() => setDragging(false)}
                onDrop={onDrop}
                className={`flex min-h-24 flex-wrap items-center gap-3 rounded-lg border-2 border-dashed px-4 py-3 transition-colors ${dragging ? 'border-jge-blue bg-jge-blue-50' : 'border-line-strong bg-surface-muted'}`}
              >
                <FileSpreadsheet className="h-8 w-8 shrink-0 text-jge-blue" aria-hidden="true" />
                <div className="min-w-0 flex-1 text-sm">
                  {file ? (
                    <>
                      <p className="truncate font-medium">{file.name}</p>
                      <p className="text-ink-muted">{formatBytes(file.size)}</p>
                    </>
                  ) : (
                    <p className="text-ink-muted">Arrastra el archivo aquí o elígelo desde tu equipo. Máximo {MAX_MB} MB.</p>
                  )}
                </div>
                <input
                  ref={input}
                  id="import-file"
                  type="file"
                  accept=".xlsx"
                  className="sr-only"
                  aria-label="Archivo de Excel"
                  onChange={(e) => pick(e.target.files?.[0])}
                />
                <Button type="button" variant="secondary" small onClick={() => input.current?.click()}>
                  {file ? 'Cambiar archivo' : 'Elegir archivo'}
                </Button>
              </div>
            </div>
          </div>
          <div className="flex justify-end">
            <Button type="submit" loading={uploading} disabled={!file || !selectedType} icon={<Upload className="h-4 w-4" aria-hidden="true" />}>
              {uploading ? 'Subiendo…' : 'Subir y validar'}
            </Button>
          </div>
        </form>
      </Card>

      <div>
        <PageHeader level={2} title="Historial" />
        <FormError message={history.error} />
        <DataTable label="Historial de importaciones">
          <thead>
            <tr>
              <th className={th}>Fecha</th><th className={th}>Archivo</th><th className={th}>Tipo</th>
              {isAdmin && <th className={th}>Usuario</th>}
              <th className={th}>Estado</th><th className={th}>Documentos</th><th className={th}>Errores</th>
              <th className={th}><span className="sr-only">Acciones</span></th>
            </tr>
          </thead>
          <tbody>
            {history.loading && !history.data && <TableSkeleton cols={isAdmin ? 8 : 7} />}
            {batches.map((b) => (
              <tr key={b.id} className={tr}>
                <td className={`${td} tabular whitespace-nowrap`}>{formatDateTime(b.created_at)}</td>
                <td className={`${td} max-w-[16rem] truncate font-medium`} title={b.original_name}>{b.original_name}</td>
                <td className={td}>{b.type_label}</td>
                {isAdmin && <td className={`${td} font-mono text-[13px]`}>{b.user?.username}</td>}
                <td className={td}><ImportStatusBadge status={b.status} label={b.status_label} /></td>
                <td className={`${td} tabular`}>{b.documents_total}</td>
                <td className={`${td} tabular`}>{b.errors_total}{b.warnings_total > 0 && <span className="text-ink-muted"> (+{b.warnings_total} avisos)</span>}</td>
                <td className={`${td} text-right`}>
                  <Button variant="ghost" small onClick={() => navigate(`/importaciones/${b.id}`)} aria-label={`Ver importación ${b.original_name}`}>Ver</Button>
                </td>
              </tr>
            ))}
            {!history.loading && batches.length === 0 && (
              <EmptyRow cols={isAdmin ? 8 : 7}><FileSpreadsheet className="mx-auto mb-2 h-6 w-6 text-ink-subtle" aria-hidden="true" />Aún no hay importaciones.</EmptyRow>
            )}
          </tbody>
        </DataTable>

        {history.data && history.data.last_page > 1 && (
          <nav className="mt-3 flex items-center justify-between text-sm" aria-label="Paginación">
            <span className="tabular text-ink-muted">Página {history.data.current_page} de {history.data.last_page}</span>
            <div className="flex gap-2">
              <Button variant="secondary" small icon={<ChevronLeft className="h-4 w-4" aria-hidden="true" />} disabled={page <= 1} onClick={() => setPage(page - 1)}>Anterior</Button>
              <Button variant="secondary" small disabled={page >= history.data.last_page} onClick={() => setPage(page + 1)}>
                Siguiente<ChevronRight className="h-4 w-4" aria-hidden="true" />
              </Button>
            </div>
          </nav>
        )}
      </div>
    </section>
  )
}
