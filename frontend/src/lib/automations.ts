export interface AutomationParam {
  name: string
  label: string
  unit: string
  help: string
  min: number
  max: number
  default: number
  value: number
}

export interface AutomationRunSummary {
  status: 'running' | 'ok' | 'failed'
  trigger: 'schedule' | 'manual'
  started_at: string
  finished_at: string | null
  attempt: number
  error: string | null
  result: Record<string, unknown> | null
}

export interface Automation {
  key: string
  label: string
  description: string
  frequency: 'daily' | 'weekly' | 'hourly'
  respect_window: boolean
  available: boolean
  enabled: boolean
  params: AutomationParam[]
  last_run: AutomationRunSummary | null
}

export interface AutomationList {
  data: Automation[]
  meta: { master_enabled: boolean; window: { start: number; end: number }; retry_minutes: number[] }
}

export interface AutomationRunRow {
  id: number
  automation: string
  label: string
  trigger: 'schedule' | 'manual'
  status: 'running' | 'ok' | 'failed'
  attempt: number
  started_at: string
  finished_at: string | null
  next_retry_at: string | null
  result: Record<string, unknown> | null
  error: string | null
}

export const FREQUENCY_LABEL = { daily: 'Cada día', weekly: 'Cada semana (lunes)', hourly: 'Cada hora' } as const
export const RUN_STATUS_LABEL = { running: 'En curso', ok: 'Correcta', failed: 'Con error' } as const
export const TRIGGER_LABEL = { schedule: 'Programada', manual: 'Manual' } as const

/** Resumen legible del resultado de una ejecución, p. ej. «notified: 3». */
export function describeResult(result: Record<string, unknown> | null): string {
  return result ? Object.entries(result).map(([k, v]) => `${k}: ${String(v)}`).join(' · ') : ''
}
