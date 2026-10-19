import { CheckCircle2, CircleSlash, Clock, Loader2, TriangleAlert, XCircle, type LucideIcon } from 'lucide-react'
import type { ImportStatus } from '../lib/types'

const styles: Record<ImportStatus, { icon: LucideIcon; box: string; spin?: boolean }> = {
  pending: { icon: Clock, box: 'border-jge-blue/30 bg-info-50 text-jge-blue' },
  validating: { icon: Loader2, box: 'border-jge-blue/30 bg-info-50 text-jge-blue', spin: true },
  validated: { icon: CheckCircle2, box: 'border-success/30 bg-success-50 text-success' },
  rejected: { icon: XCircle, box: 'border-danger/30 bg-danger-50 text-danger' },
  importing: { icon: Loader2, box: 'border-jge-blue/30 bg-info-50 text-jge-blue', spin: true },
  imported: { icon: CheckCircle2, box: 'border-success/30 bg-success-50 text-success' },
  discarded: { icon: CircleSlash, box: 'border-line-strong bg-surface-muted text-ink-muted' },
  failed: { icon: TriangleAlert, box: 'border-warning/40 bg-warning-50 text-warning' },
}

/** Estado de un lote: icono + texto (nunca solo color). */
export function ImportStatusBadge({ status, label }: { status: ImportStatus; label: string }) {
  const s = styles[status]
  const Icon = s.icon
  return (
    <span className={`inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs font-medium ${s.box}`}>
      <Icon className={`h-3.5 w-3.5 ${s.spin ? 'animate-spin' : ''}`} aria-hidden="true" />
      {label}
    </span>
  )
}
