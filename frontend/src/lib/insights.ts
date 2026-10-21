export interface InsightAnalysis {
  key: string
  label: string
  available: boolean
  reason: string | null
  required_months: number
  required_documents: number | null
  last_run: { computed_at: string; status: 'ok' | 'insufficient'; period_from: string | null; period_to: string | null } | null
}

export interface InsightCompany {
  company_id: number
  company: string
  first_sale: string | null
  last_sale: string | null
  history_months: number
  documents: number
  stale: boolean
  days_since_last_sale: number | null
  analyses: InsightAnalysis[]
}

export interface InsightParameter {
  key: string
  group: string
  label: string
  unit: string
  help: string
  min: number
  max: number
  default: number
  value: number
  customized: boolean
}

export interface InsightRunRow {
  id: number
  company_id: number
  analysis: string
  status: 'ok' | 'insufficient'
  period_from: string | null
  period_to: string | null
  computed_at: string
  note: string | null
}

export type RfmSegment = 'new' | 'champion' | 'loyal' | 'promising' | 'at_risk' | 'dormant' | 'lost'

export interface RfmRow {
  code: string
  name: string
  segment: RfmSegment
  segment_label: string
  first_purchase: string
  last_purchase: string
  recency_days: number
  frequency: number
  gross: string
  returns: string
  monetary: string
  r: number
  f: number
  m: number
  explanation: string
}

export interface RfmRun {
  id: number
  status: 'ok' | 'insufficient'
  computed_at: string
  period_from: string | null
  period_to: string | null
  note: string | null
  customers: number | null
  parameters: Record<string, number>
}

export interface RfmList {
  data: RfmRow[]
  meta: {
    page: number
    has_more: boolean
    counts: Record<RfmSegment, number>
    run: RfmRun | null
    segments: { key: RfmSegment; label: string; rule: string }[]
  }
}

export interface ConcentrationEntry {
  code: string
  name: string
  net: string
  share_pct: number
  cumulative_pct: number
}

export interface ConcentrationBlock {
  count: number
  total: string
  top1_pct: number
  top10_pct: number
  n80: number
  n80_pct: number
  hhi: number
  top: ConcentrationEntry[]
  curve: { entities_pct: number; sales_pct: number }[]
  alerts: ConcentrationEntry[]
}

export interface AnalysisRun<S> {
  id: number
  status: 'ok' | 'insufficient'
  computed_at: string
  period_from: string | null
  period_to: string | null
  note: string | null
  summary: S | null
  parameters: Record<string, number>
}

export interface ConcentrationSummary {
  alert_pct: number
  customers: ConcentrationBlock
  products: ConcentrationBlock
}

export interface CohortRow {
  month: string
  customers: number
  prior: boolean
  retention: (number | null)[]
}

export interface CohortSummary {
  depth: number
  first_month: string
  last_month: string
  cohorts: CohortRow[]
  average: (number | null)[]
}

export interface CrossSellRun {
  id: number
  status: 'ok' | 'insufficient'
  computed_at: string
  period_from: string | null
  period_to: string | null
  note: string | null
  customers: number | null
  rules: number | null
  opportunities: number | null
  level: 'línea' | 'categoría'
  parameters: Record<string, number>
}

export interface CrossSellRule {
  id: number
  antecedent: string
  consequent: string
  antecedent_customers: number
  consequent_customers: number
  both_customers: number
  total_customers: number
  confidence_pct: number
  lift: number
  explanation: string
}

export interface CrossSellOpportunity {
  code: string
  name: string
  suggestion: string
  because: string
  confidence_pct: number
  support: number
  lift: number
  explanation: string
}

export type AlertType = 'inactivity_risk' | 'sales_drop' | 'sales_rise' | 'abandoned_line' | 'cross_sell' | 'rfm_at_risk'

export const ALERT_LABEL: Record<AlertType, string> = {
  inactivity_risk: 'Riesgo de inactividad',
  sales_drop: 'Caída de venta',
  sales_rise: 'Alza de venta',
  abandoned_line: 'Categoría abandonada',
  cross_sell: 'Oportunidad de venta cruzada',
  rfm_at_risk: 'Cliente en riesgo (RFM)',
}

export const CLOSE_REASON_LABEL = {
  contacted: 'Ya contacté al cliente',
  not_applicable: 'No aplica',
  do_not_contact: 'El cliente no desea ser contactado',
  resolved: 'Resuelto',
  other: 'Otro (con nota)',
} as const

export type CloseReason = keyof typeof CLOSE_REASON_LABEL

export const SEVERITY_LABEL = { high: 'Alta', medium: 'Media', low: 'Baja' } as const

export interface InsightAlertRow {
  id: number
  code: string
  name: string
  type: AlertType
  severity: 'high' | 'medium' | 'low'
  status: 'open' | 'resolved' | 'closed'
  explanation: string
  facts: Record<string, string | number | null>
  first_detected_at: string
  last_detected_at: string
  run_id: number
  follow_up: boolean
  closed: { at: string | null; reason: CloseReason | null; note: string | null; by: string | null } | null
}

export interface InsightAlertList {
  data: InsightAlertRow[]
  meta: { page: number; has_more: boolean; counts: Record<AlertType, number> }
}

export interface SeasonalIndex {
  indices: (number | null)[]
  observations: number[]
  peak: number | null
  trough: number | null
  amplitude: number | null
  marked: boolean
}

export interface SeasonalitySummary {
  months: number
  first_month: string
  last_month: string
  company: SeasonalIndex
  lines: (SeasonalIndex & { name: string })[]
  yoy: { month: string; value: string; previous: string; change_pct: number | null }[]
  marked_points: number
}

export const MONTH_NAMES = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre']
