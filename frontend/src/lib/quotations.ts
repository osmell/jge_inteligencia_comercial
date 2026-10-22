import type { components } from './api-types'

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

type Schemas = components['schemas']

/** Campos que la API declara como texto pero son listas cerradas: el documento generado no conoce esos valores. */
interface Narrowed {
  status: StoredStatus
  effective_status: QuotationStatus
  loss_reason: LossReason | null
}

/** Forma de la respuesta, generada desde el backend (`npm run api:types`); aquí solo se precisan las listas cerradas. */
export type QuotationRow = Omit<Schemas['QuotationResource'], keyof Narrowed> & Narrowed

export type QuotationDetail = Omit<Schemas['QuotationDetailResource'], keyof Narrowed | 'transitions' | 'events'> &
  Narrowed & {
    transitions: StoredStatus[]
    events: (Omit<Schemas['QuotationDetailResource']['events'][number], 'from' | 'to'> & { from: StoredStatus | null; to: StoredStatus })[]
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
