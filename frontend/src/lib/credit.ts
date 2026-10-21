export interface CreditInvoiceRow {
  id: number
  company_id: number
  company: string
  document: string
  issued_on: string
  customer: { code: string; name: string }
  seller: string
  total: string
  balance: string
  paid: string
  age_days: number
  bucket: string | null
  sales_linked: boolean
}

export interface CreditList {
  data: CreditInvoiceRow[]
  meta: { page: number; has_more: boolean; open_count: number; open_balance: string; buckets: string[] }
}

export type MovementType = 'invoice' | 'payment' | 'credit_note' | 'adjustment' | 'reversal'

export const MOVEMENT_LABEL: Record<MovementType, string> = {
  invoice: 'Factura',
  payment: 'Abono',
  credit_note: 'Nota de crédito',
  adjustment: 'Ajuste',
  reversal: 'Anulación',
}

export interface CreditMovementRow {
  id: number
  type: MovementType
  amount: string
  occurred_on: string
  method: string | null
  reference: string | null
  note: string | null
  user: string | null
  reverses_id: number | null
  reversed: boolean
}

export interface CreditInvoiceDetail extends CreditInvoiceRow {
  branch: string
  subtotal: string
  discount_total: string
  tax_total: string
  movements: CreditMovementRow[]
}

/** Etiqueta de un tramo de antigüedad: «current» → «Al día», «1-30» → «1 a 30 días», «121+» → «Más de 120 días». */
export function bucketLabel(bucket: string | null): string {
  if (bucket === null) return '—'
  if (bucket === 'current') return 'Al día'
  if (bucket.endsWith('+')) return `Más de ${Number.parseInt(bucket, 10) - 1} días`
  return `${bucket.replace('-', ' a ')} días`
}

export interface OpenInvoice {
  id: number
  document: string
  issued_on: string
  total: string
  balance: string
}

/** Los importes se manejan en unidades de 4 decimales para repartir sin errores de redondeo. */
export const UNITS = 10000
export const toUnits = (value: string) => Math.round(Number(value.replace(',', '.')) * UNITS) || 0
export const fromUnits = (units: number) => (units / UNITS).toFixed(4).replace(/\.?0+$/, '')

/** Reparto de un importe: de la factura más antigua a la más reciente, sin pasar del saldo de cada una. */
export function allocateOldestFirst(invoices: OpenInvoice[], amount: number): Record<number, string> {
  const result: Record<number, string> = {}
  let left = amount
  for (const inv of invoices) {
    if (left <= 0) break
    const part = Math.min(left, toUnits(inv.balance))
    result[inv.id] = fromUnits(part)
    left -= part
  }
  return result
}

export interface AgingCustomer {
  company_id: number
  company: string
  code: string
  name: string
  buckets: Record<string, string>
  total: string
  invoices: number
  oldest_days: number
}

export interface AgingReport {
  data: { buckets: string[]; totals: Record<string, string>; customers: AgingCustomer[] }
  meta: { customers_total: number; truncated: boolean }
}

export interface CustomerCreditSummary {
  buckets: string[]
  totals: Record<string, string>
  open_invoices: number
  oldest_days: number
  returns: { count: number; subtotal: string; tax_total: string; total: string }
}

export type PromiseStatus = 'open' | 'kept' | 'broken' | 'cancelled'

export const PROMISE_LABEL: Record<PromiseStatus, string> = {
  open: 'Pendiente',
  kept: 'Cumplida',
  broken: 'Incumplida',
  cancelled: 'Cancelada',
}

export interface PaymentPromiseRow {
  id: number
  company_id: number
  customer: { code: string; name: string }
  made_on: string
  promised_on: string
  amount: string
  paid: string
  status: PromiseStatus
  note: string | null
  days_overdue: number
  user: string | null
  cancel_reason: string | null
}
