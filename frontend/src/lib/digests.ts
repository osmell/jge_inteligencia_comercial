export interface DigestSummary {
  id: number
  company_id: number
  kind: 'daily' | 'weekly'
  period_key: string
  period_from: string
  period_to: string
  title: string
  generated_at: string
}

export type MetricFormat = 'money' | 'int' | 'pct' | 'text'

export interface DigestMetric {
  label: string
  value: number | string | null
  format: MetricFormat
  hint?: string
  tone?: 'good' | 'bad' | 'neutral'
}

export interface DigestTable {
  title: string
  columns: { key: string; label: string; format: MetricFormat }[]
  rows: Record<string, number | string | null>[]
}

export interface DigestSection {
  key: string
  title: string
  metrics: DigestMetric[]
  tables?: DigestTable[]
}

export interface DigestDetail extends DigestSummary {
  sections: DigestSection[]
}

export const KIND_LABEL = { daily: 'Diario', weekly: 'Semanal' } as const
