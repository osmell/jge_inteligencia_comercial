import type { Point } from '../components/charts'

export type Profile = 'company' | 'team' | 'seller'

export interface DashboardMeta {
  profile: Profile
  linked_sellers: number | null
  sections: string[]
  periods: string[]
  companies: { id: number; name: string }[]
  last_sale: string | null
  last_import: string | null
}

export const PERIOD_LABEL: Record<string, string> = { month: 'Mes en curso', prev_month: 'Mes anterior', quarter: 'Trimestre en curso', year: 'Año en curso' }

export interface Comparison {
  from: string
  to: string
  net_total: string
  documents: number
  change_pct: number | null
}

export interface SalesSection {
  period: { from: string; to: string; label: string }
  current: { net_total: string; documents: number; customers: number; average_ticket: string | null }
  previous: Comparison
  year_ago: Comparison | null
  daily: { date: string; net_total: string; documents: number }[]
  daily_previous: { date: string; net_total: string; documents: number }[]
  monthly: { month: string; net_total: string; documents: number }[]
  history_since: string | null
}

export interface GoalRow {
  seller_id: number
  seller: string
  effective_goal: number | null
  actual: number
  compliance_pct: number | null
  projected: number | null
  projected_pct: number | null
  status: string
  behind_pace: boolean
}

export interface GoalsSection {
  month: string
  state: 'future' | 'current' | 'closed'
  totals: { goal: number; actual: number; remaining: number; compliance_pct: number | null; projected: number | null; projected_pct: number | null; sellers_with_goal: number; met: number }
  rows: GoalRow[]
}

export interface RankRow {
  key: string
  label: string
  net_total: string
  documents: number
}

export interface RankingsSection {
  customers: RankRow[]
  products: RankRow[]
  sellers?: RankRow[]
}

export interface CompaniesSection {
  rows: { company_id: number; company: string; net_total: string; documents: number; previous_net_total: string; change_pct: number | null }[]
}

export interface CreditSection {
  total: string
  overdue: string
  overdue_pct: number | null
  invoices: number
  customers: number
  buckets: { bucket: string; amount: string }[]
  top_debtors: { company_id: number; code: string; name: string; total: string; oldest_days: number }[]
  promises: { due_next_7_days: number; due_amount: string; broken_30_days: number }
}

export interface QuotationsSection {
  open: { count: number; amount: string }
  expiring: { count: number; amount: string }
  expired_unclosed: number
  issued: { count: number; amount: string }
  converted: { count: number; amount: string }
}

export interface FollowupSection {
  overdue: number
  today: number
}

export interface InventorySection {
  out_of_stock: number
  below_min: number
  no_rotation: number
  above_max: number
}

export interface InsightsSection {
  open: number
  by_severity: { high: number; medium: number; low: number }
  at_risk: number
  sales_drop: number
}

/** Días entre dos fechas ISO, ambas incluidas. */
function daysBetween(from: string, to: string): number {
  return Math.round((new Date(`${to}T12:00:00`).getTime() - new Date(`${from}T12:00:00`).getTime()) / 86_400_000) + 1
}

/** Una cifra por día del tramo (0 en los días sin venta), para comparar el día n de un periodo con el día n del otro. */
export function dailyPoints(rows: { date: string; net_total: string }[], from: string, to: string): Point[] {
  const byDate = new Map(rows.map((r) => [r.date, Number(r.net_total)]))
  return Array.from({ length: Math.max(daysBetween(from, to), 0) }, (_, i) => {
    const d = new Date(`${from}T12:00:00`)
    d.setDate(d.getDate() + i)
    const key = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
    return { key, value: byDate.get(key) ?? 0 }
  })
}

/** «+12,5 %» / «−3 %» / «Sin base de comparación». */
export function formatChange(pct: number | null): string {
  if (pct === null) return 'Sin base de comparación'
  const abs = Math.abs(pct).toLocaleString('es', { maximumFractionDigits: 1 })
  return `${pct > 0 ? '+' : pct < 0 ? '−' : ''}${abs} %`
}
