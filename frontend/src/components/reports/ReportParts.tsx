import { CircleAlert, CircleCheck } from 'lucide-react'
import { formatDate } from '../../lib/customers'
import { formatMoney } from '../../lib/format'
import { REPORT_NOTE, type SellerReport } from '../../lib/reports'
import { Alert, Card } from '../ui'

/** Piezas comunes a los reportes de evaluación: tarjeta de cifra, línea del periodo, notas, contraste y leyenda. */

export function ReportStat({ label, value, hint }: { label: string; value: string; hint?: string }) {
  return (
    <Card className="p-4">
      <p className="text-sm text-ink-muted">{label}</p>
      <p className="tabular mt-0.5 text-2xl font-semibold tracking-tight">{value}</p>
      {hint && <p className="mt-1 text-xs text-ink-muted">{hint}</p>}
    </Card>
  )
}

type Period = Pick<SellerReport, 'period' | 'comparison'>

export function PeriodLine({ data }: { data: Period }) {
  return (
    <p className="mb-3 text-sm text-ink-muted">
      Periodo {formatDate(data.period.from)} – {formatDate(data.period.to)} ({data.period.days} días) · comparado con {formatDate(data.comparison.from)} – {formatDate(data.comparison.to)} ({data.comparison.days} días)
    </p>
  )
}

export function ReportNotes({ notes }: { notes: string[] }) {
  return notes.map((note) => <div key={note} className="mb-2"><Alert tone="info">{REPORT_NOTE[note] ?? note}</Alert></div>)
}

type Rec = SellerReport['reconciliation']

/** Contraste entre la venta consolidada y la suma de las filas del reporte. `who` va en plural: «los vendedores», «los operadores». */
export function ReconciliationNote({ rec, who }: { rec: Rec; who: string }) {
  return (
    <div className="mb-4">
      {rec.matches ? (
        <p className="flex items-center gap-2 text-sm text-success"><CircleCheck className="h-4 w-4" aria-hidden="true" />Contraste: la venta consolidada ({formatMoney(rec.consolidated_net)}, {rec.consolidated_documents.toLocaleString('es')} documentos) coincide con la suma de {who}.</p>
      ) : (
        <Alert tone="danger">La venta consolidada ({formatMoney(rec.consolidated_net)}) no coincide con la suma de {who} ({formatMoney(rec.rows_net)}): diferencia de {formatMoney(rec.net_difference)} y {rec.documents_difference} documentos.</Alert>
      )}
    </div>
  )
}

export function ReportLegend({ children }: { children?: React.ReactNode }) {
  return (
    <p className="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-ink-muted">
      <span className="inline-flex items-center gap-1"><CircleAlert className="h-3.5 w-3.5 text-danger" aria-hidden="true" />Crítico</span>
      <span>Los umbrales de cada semáforo son valores iniciales por omisión.</span>
      <span>Toda cifra de venta es neta (sin IVA).</span>
      {children}
    </p>
  )
}
