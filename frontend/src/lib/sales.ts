export interface Kpis {
  net_total: string
  tax_total: string
  grand_total: string
  units: string
  documents: number
  customers: number
  average_ticket: string | null
  estimated_margin: string | null
  estimated_margin_pct: number | null
  cost_coverage_pct: number | null
}

export type KpiKey = 'net_total' | 'tax_total' | 'grand_total' | 'units' | 'documents' | 'customers' | 'average_ticket' | 'estimated_margin'

export interface SalesSummary {
  period: { from: string; to: string; days: number }
  previous_period: { from: string; to: string; days: number }
  current: Kpis
  previous: Kpis
  change_pct: Record<KpiKey, number | null>
  first_sale_date: string | null
  warnings: string[]
}

export interface BreakdownRow extends Kpis {
  key: string
  label: string
}

export interface Breakdown {
  data: BreakdownRow[]
  meta: { dimension: string; truncated: boolean }
}

export type Dimension = 'company' | 'branch' | 'seller' | 'operator' | 'category' | 'product' | 'customer'

/** Filtro de API que se aplica al elegir una fila de cada dimensión. */
export const DIMENSION_FILTER: Record<Dimension, string> = {
  company: 'company_id',
  branch: 'branch_id',
  seller: 'seller_id',
  operator: 'operator_id',
  category: 'category',
  product: 'product_code',
  customer: 'customer_code',
}

export const DIMENSION_LABEL: Record<Dimension, string> = {
  company: 'Compañía',
  branch: 'Sucursal',
  seller: 'Vendedor',
  operator: 'Operador',
  category: 'Categoría',
  product: 'Producto',
  customer: 'Cliente',
}

export type Preset = 'month' | 'last-month' | '30d' | '90d' | 'ytd' | 'custom'

export const PRESET_LABEL: Record<Preset, string> = {
  month: 'Este mes',
  'last-month': 'Mes anterior',
  '30d': 'Últimos 30 días',
  '90d': 'Últimos 90 días',
  ytd: 'Año a la fecha',
  custom: 'Personalizado',
}

/** Fecha local como YYYY-MM-DD (sin pasar por UTC, que cambiaría el día cerca de la medianoche). */
export function isoDate(d: Date): string {
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
}

export function presetRange(preset: Exclude<Preset, 'custom'>, today = new Date()): { from: string; to: string } {
  const y = today.getFullYear()
  const m = today.getMonth()
  switch (preset) {
    case 'month':
      return { from: isoDate(new Date(y, m, 1)), to: isoDate(today) }
    case 'last-month':
      return { from: isoDate(new Date(y, m - 1, 1)), to: isoDate(new Date(y, m, 0)) }
    case '30d':
      return { from: isoDate(new Date(y, today.getMonth(), today.getDate() - 29)), to: isoDate(today) }
    case '90d':
      return { from: isoDate(new Date(y, today.getMonth(), today.getDate() - 89)), to: isoDate(today) }
    case 'ytd':
      return { from: isoDate(new Date(y, 0, 1)), to: isoDate(today) }
  }
}

export function daysBetween(from: string, to: string): number {
  return Math.round((Date.parse(`${to}T00:00:00Z`) - Date.parse(`${from}T00:00:00Z`)) / 86_400_000) + 1
}

export function formatPct(value: number | null | undefined): string {
  if (value === null || value === undefined) return '—'
  return `${value.toLocaleString('es', { minimumFractionDigits: 1, maximumFractionDigits: 1 })} %`
}

const compact = new Intl.NumberFormat('es', { notation: 'compact', maximumFractionDigits: 1 })
export const formatCompact = (value: number): string => compact.format(value)

/** '2026-10-05' → '5 oct'; '2026-10' → 'oct 2026'. */
export function formatBucket(key: string): string {
  if (/^\d{4}-\d{2}$/.test(key)) {
    return new Date(`${key}-01T12:00:00`).toLocaleDateString('es', { month: 'short', year: 'numeric' })
  }
  return new Date(`${key}T12:00:00`).toLocaleDateString('es', { day: 'numeric', month: 'short' })
}
