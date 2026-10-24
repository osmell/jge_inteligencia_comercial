import { FileSpreadsheet, FileText, Mail, Printer } from 'lucide-react'
import { useState } from 'react'
import { flushSync } from 'react-dom'
import { useCurrentUser } from '../../auth/AuthContext'
import { download } from '../../lib/api'
import type { ReportFilters } from '../../lib/useReportFilters'
import { useExport } from '../../lib/useExport'
import { Button, FormError } from '../ui'
import EmailReportModal from './EmailReportModal'

export type ReportKey = 'sellers' | 'operators' | 'branches' | 'conciliation'

/**
 * Las salidas de un reporte: imprimir lo que se ve, descargarlo en Excel o PDF y enviarlo por correo. Todas parten del mismo periodo y
 * filtros de la pantalla. Al imprimir, esta barra y los filtros no salen (`print:hidden`) y se agrega quién lo imprimió.
 */
export default function ReportActions({ report, filters }: { report: ReportKey; filters: ReportFilters }) {
  const user = useCurrentUser()
  const exporter = useExport<'xlsx' | 'pdf'>()
  const [mailing, setMailing] = useState(false)
  const [printedAt, setPrintedAt] = useState<string | null>(null)
  const ready = filters.valid

  /** La hora de impresión se fija al pulsar (y se pinta antes de abrir el diálogo), no al dibujar la pantalla. */
  function print() {
    flushSync(() => setPrintedAt(new Date().toLocaleString('es')))
    window.print()
  }

  function file(format: 'xlsx' | 'pdf') {
    void exporter.run(format, () => download(`/reports/${report}/export?${filters.query({ format })}`, `reporte.${format}`), 'No se pudo generar el archivo.')
  }

  return (
    <>
      <div className="mb-4 flex flex-wrap items-center gap-2 print:hidden" role="group" aria-label="Salidas del reporte">
        <Button variant="secondary" small icon={<Printer className="h-4 w-4" aria-hidden="true" />} disabled={!ready} onClick={print}>Imprimir</Button>
        <Button variant="secondary" small icon={<FileSpreadsheet className="h-4 w-4" aria-hidden="true" />} loading={exporter.exporting === 'xlsx'} disabled={!ready || exporter.exporting !== null} onClick={() => file('xlsx')}>Excel</Button>
        <Button variant="secondary" small icon={<FileText className="h-4 w-4" aria-hidden="true" />} loading={exporter.exporting === 'pdf'} disabled={!ready || exporter.exporting !== null} onClick={() => file('pdf')}>PDF</Button>
        <Button variant="secondary" small icon={<Mail className="h-4 w-4" aria-hidden="true" />} disabled={!ready} onClick={() => setMailing(true)}>Enviar por correo</Button>
      </div>
      <FormError message={exporter.error} />
      <p className="mb-2 hidden text-xs text-ink-muted print:block">Impreso por {user?.name} el {printedAt}</p>
      {mailing && <EmailReportModal report={report} filters={filters} onClose={() => setMailing(false)} />}
    </>
  )
}
