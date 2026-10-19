import type { BreakdownRow, Kpis } from './sales'

export interface CustomerListRow {
  company_id: number
  company: string
  code: string
  name: string
  trade_name: string | null
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
}

export interface CustomerProfile {
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
