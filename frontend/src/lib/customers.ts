import type { BreakdownRow, Kpis } from './sales'

export type Abc = 'A' | 'B' | 'C'

export const ACTIVITY_LABEL = {
  new: 'Nuevo',
  recovered: 'Recuperado',
  active: 'Activo',
  inactive: 'Inactivo',
  lost: 'Perdido',
  no_sales: 'Sin compras',
} as const

export type ActivityStatus = keyof typeof ACTIVITY_LABEL

export interface SellerRow {
  id: number
  name: string
  company_id: number
  is_active: boolean
  branch_id: number | null
  branch: string | null
  is_default: boolean
  user_id: number | null
  supervisor: { id: number; name: string } | null
}

export interface OperatorRow {
  id: number
  name: string
  company_id: number
  is_active: boolean
}

export interface Assignment {
  seller: { id: number; name: string; assigned: boolean } | null
  operator: { id: number; name: string } | null
  supervisor: { id: number; name: string } | null
}

export interface ActivityRules {
  inactive_days: number
  lost_days: number
}

export interface CustomerType {
  id: number
  name: string
  active: boolean
}

export const ABC_HINT: Record<Abc, string> = {
  A: 'Clase A: concentra hasta el 80 % de la venta de los últimos 12 meses.',
  B: 'Clase B: del 80 % al 95 % acumulado de la venta de los últimos 12 meses.',
  C: 'Clase C: el 5 % final de la venta de los últimos 12 meses.',
}

export interface CustomerListRow {
  company_id: number
  company: string
  code: string
  name: string
  trade_name: string | null
  type: string | null
  seller: string | null
  seller_is_default: boolean
  abc: Abc | null
  activity: ActivityStatus
  documents: number
  net_total: string
  first_purchase: string | null
  last_purchase: string | null
}

export interface CustomerMaster {
  id: number
  company_id: number
  company: string
  branch: string | null
  branch_id: number | null
  code: string
  name: string
  trade_name: string | null
  contact_name: string | null
  phone: string | null
  whatsapp: string | null
  email: string | null
  address: string | null
  source: 'import' | 'sales' | 'manual'
  do_not_contact: boolean
  do_not_contact_reason: string | null
  type: { id: number; name: string } | null
  abc: Abc | null
}

export interface CustomerProfile {
  activity: ActivityRules & { status: ActivityStatus }
  assignment: Assignment
  customer: CustomerMaster
  first_purchase: string | null
  last_purchase: string | null
  days_since_last_purchase: number | null
  history_starts_on: string | null
  totals: Kpis
  frequency: { purchase_days: number; average_days_between: number | null }
  monthly: { key: string; net_total: string }[]
  top_products: BreakdownRow[]
  top_categories: BreakdownRow[]
  sellers: BreakdownRow[]
  branches: BreakdownRow[]
}

export interface CustomerPurchase {
  id: number
  document: string
  issued_on: string
  branch: string
  seller: string
  operator: string
  lines: number
  net_total: string
  grand_total: string
}

export interface DocumentLine {
  line: number
  product_code: string
  product_name: string
  category: string
  units: string
  net_total: string
  grand_total: string
}

export const SORT_LABEL = {
  last_purchase: 'Última compra',
  net_total: 'Venta neta',
  documents: 'Documentos',
  name: 'Nombre',
} as const

export type CustomerSort = keyof typeof SORT_LABEL

/** '2026-10-05' → '5 oct 2026'. */
export function formatDate(iso: string): string {
  return new Date(`${iso}T12:00:00`).toLocaleDateString('es', { day: 'numeric', month: 'short', year: 'numeric' })
}

export function sinceLabel(days: number): string {
  if (days <= 0) return 'hoy'
  return days === 1 ? 'hace 1 día' : `hace ${days} días`
}

export const profileUrl = (code: string, companyId: number | string) =>
  `/clientes/ficha?${new URLSearchParams({ codigo: code, compania: String(companyId) })}`

export interface ReportPerson {
  name: string
  net_total: string
}

export interface CustomerSalesRow {
  company_id: number
  company: string
  code: string
  name: string
  type: string | null
  assigned_seller: string | null
  assigned_seller_is_default: boolean
  supervisor: string | null
  net_total: string
  documents: number
  last_purchase: string
  direct_net_total: string
  sellers: ReportPerson[]
  sellers_more: number
  operators: ReportPerson[]
  operators_more: number
}

/** Primer y último día del mes en curso, como 'YYYY-MM-DD'. */
export function currentMonthRange(now = new Date()): { from: string; to: string } {
  const pad = (n: number) => String(n).padStart(2, '0')
  const last = new Date(now.getFullYear(), now.getMonth() + 1, 0).getDate()
  const prefix = `${now.getFullYear()}-${pad(now.getMonth() + 1)}`
  return { from: `${prefix}-01`, to: `${prefix}-${pad(last)}` }
}

export interface ProductRow {
  company_id: number
  company: string
  code: string
  name: string
  level1: string | null
  level2: string | null
  level3: string | null
  net_total: string
}

export interface MissingProductRow {
  company_id: number
  company: string
  code: string
  name: string
  category: string | null
  net_total: string
}

export interface ProductCoverage {
  catalog_products: number
  sold_products: number
  missing_products: number
  net_total: string
  missing_net_total: string
  covered_pct: number | null
}

export interface ProductLevels {
  level1: string[]
  level2: string[]
  level3: string[]
}

export type InteractionChannel = 'call' | 'email' | 'whatsapp' | 'visit' | 'meeting' | 'other'
export type InteractionDirection = 'outbound' | 'inbound'
export type InteractionOutcome = 'spoke' | 'no_answer' | 'interested' | 'quote_requested' | 'callback_requested' | 'not_interested' | 'wrong_contact'

export const CHANNEL_LABEL: Record<InteractionChannel, string> = {
  call: 'Llamada', email: 'Correo', whatsapp: 'WhatsApp (manual)', visit: 'Visita', meeting: 'Reunión', other: 'Otro',
}
export const DIRECTION_LABEL: Record<InteractionDirection, string> = { outbound: 'Contacté yo al cliente', inbound: 'El cliente me contactó' }
export const OUTCOME_LABEL: Record<InteractionOutcome, string> = {
  spoke: 'Se habló con el cliente', no_answer: 'Sin respuesta', interested: 'Interesado', quote_requested: 'Pidió cotización',
  callback_requested: 'Pidió que se le vuelva a contactar', not_interested: 'No interesado', wrong_contact: 'Contacto incorrecto',
}

export interface ContactPreference {
  do_not_contact: boolean
  reason: string | null
  since: string | null
}

export interface CustomerInteraction {
  id: number
  channel: InteractionChannel
  direction: InteractionDirection
  outcome: InteractionOutcome
  occurred_at: string
  response: string | null
  next_action: string | null
  next_action_at: string | null
  next_action_done_at: string | null
  user: { id: number; name: string } | null
  can_edit: boolean
}

/** '2026-10-05T14:30:00-06:00' → '5 oct 2026, 14:30'. */
export function formatDateTime(iso: string): string {
  return new Date(iso).toLocaleString('es', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}

/** Valor para <input type="datetime-local"> en hora local. */
export function toLocalInput(date: Date): string {
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`
}

export interface AgendaAction {
  id: number
  customer: { company_id: number; code: string; name: string; phone: string | null; whatsapp: string | null; seller: string | null }
  next_action: string
  next_action_at: string
  days_overdue: number
  origin: { channel: InteractionChannel; outcome: InteractionOutcome; occurred_at: string }
  user: { id: number; name: string } | null
}

export interface ReactivateRow {
  company_id: number
  company: string
  code: string
  name: string
  phone: string | null
  whatsapp: string | null
  status: 'inactive' | 'lost'
  last_purchase: string
  days_since_last_purchase: number
  seller: string | null
  last_contact_at: string | null
}

export interface Agenda {
  data: { overdue: AgendaAction[]; upcoming: AgendaAction[]; reactivate: ReactivateRow[] }
  meta: { overdue_total: number; upcoming_total: number; reactivate_total: number; horizon_days: number; no_contact_days: number; truncated: boolean }
}
