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
