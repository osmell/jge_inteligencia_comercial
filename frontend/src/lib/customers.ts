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
  supervisor: { id: number; name: string } | null
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
  code: string
  name: string
  trade_name: string | null
  contact_name: string | null
  phone: string | null
  whatsapp: string | null
  email: string | null
  address: string | null
  source: 'import' | 'sales'
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
