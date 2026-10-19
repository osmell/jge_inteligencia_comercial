export type GoalStatus = 'met' | 'warning' | 'danger' | 'no_goal' | 'exempt'

export interface GoalRow {
  seller_id: number
  seller: string
  company_id: number
  starts_on: string | null
  ends_on: string | null
  goal_amount: number | null
  days_off: number
  source: 'manual' | 'suggested' | null
  eligible_days: number
  days_in_month: number
  effective_goal: number | null
  actual: number
  compliance_pct: number | null
  status: GoalStatus
  expected_to_date: number | null
  pace_pct: number | null
  projected: number | null
  projected_pct: number | null
  behind_pace: boolean
}

export interface GoalTotals {
  effective_goal: number
  actual: number
  compliance_pct: number | null
  sellers: number
  with_goal: number
  met: number
  warning: number
  danger: number
  no_goal: number
  behind_pace: number
}

export interface GoalsResponse {
  data: GoalRow[]
  meta: { month: string; state: 'future' | 'current' | 'closed'; totals: GoalTotals; thresholds: { warning_pct: number; goal_pct: number; pace_alert_pct: number } }
}

export interface Suggestion {
  seller_id: number
  seller: string
  suggested_amount: number
  average: number
  months_used: number
}

export const STATUS_LABEL: Record<GoalStatus, string> = {
  met: 'Meta cumplida',
  warning: 'Cerca de la meta',
  danger: 'Por debajo',
  no_goal: 'Sin meta',
  exempt: 'Exento',
}

export const STATUS_TONE: Record<GoalStatus, string> = {
  met: 'bg-success-50 text-success',
  warning: 'bg-warning-50 text-warning',
  danger: 'bg-danger-50 text-danger',
  no_goal: 'bg-surface-muted text-ink-muted',
  exempt: 'bg-surface-muted text-ink-muted',
}

export function currentMonth(now = new Date()): string {
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`
}
