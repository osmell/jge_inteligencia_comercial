export const STATUS_LABEL = {
  draft: 'Borrador',
  issued: 'Emitida',
  sent: 'Enviada',
  following: 'En seguimiento',
  accepted: 'Aceptada',
  converted: 'Convertida en venta',
  rejected: 'Rechazada',
  expired: 'Vencida',
  cancelled: 'Cancelada',
} as const

export type QuotationStatus = keyof typeof STATUS_LABEL
export type StoredStatus = Exclude<QuotationStatus, 'expired'>

export const LOSS_REASON_LABEL = {
  price: 'Precio',
  competitor: 'Eligió a la competencia',
  no_budget: 'Sin presupuesto',
  no_response: 'No respondió',
  availability: 'Sin disponibilidad',
  other: 'Otro motivo',
} as const

export type LossReason = keyof typeof LOSS_REASON_LABEL

/** Texto del botón que lleva la cotización a ese estado. */
export const TRANSITION_LABEL: Record<StoredStatus, string> = {
  draft: 'Borrador',
  issued: 'Emitir',
  sent: 'Marcar como enviada',
  following: 'Pasar a seguimiento',
  accepted: 'Marcar como aceptada',
  converted: 'Convertida',
  rejected: 'Rechazar (perdida)',
  cancelled: 'Cancelar',
}

export interface QuotationRow {
  id: number
  number: string
  external_reference: string | null
  status: StoredStatus
  effective_status: QuotationStatus
  amount: string
  valid_until: string | null
  issued_at: string | null
  customer: { company_id: number; code: string; name: string; do_not_contact: boolean }
  seller: string | null
  user: { id: number; name: string } | null
  has_attachment: boolean
  document: { id: number; number: string; issued_on: string } | null
  loss_reason: LossReason | null
}

export interface QuotationDetail extends QuotationRow {
  description: string | null
  loss_note: string | null
  converted_at: string | null
  attachment: { name: string; mime: string; size: number } | null
  transitions: StoredStatus[]
  events: { from: StoredStatus | null; to: StoredStatus; note: string | null; at: string; user: string | null }[]
}

export interface QuotationList {
  data: QuotationRow[]
  meta: { page: number; has_more: boolean; counts: Partial<Record<QuotationStatus, { count: number; amount: number }>> }
}

export interface CandidateDocument {
  id: number
  document: string
  issued_on: string
  net_total: string
}

export const MAX_ATTACHMENT_MB = 15

/** '2026-10-05' → '5 oct 2026'. */
export function formatDay(iso: string): string {
  return new Date(`${iso.slice(0, 10)}T12:00:00`).toLocaleDateString('es', { day: 'numeric', month: 'short', year: 'numeric' })
}
