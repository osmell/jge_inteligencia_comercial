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
